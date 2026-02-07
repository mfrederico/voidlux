<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Agent;

use Aoe\Session\Status;
use Swoole\Coroutine;
use VoidLux\Swarm\Model\AgentModel;
use VoidLux\Swarm\Model\TaskStatus;
use VoidLux\Swarm\Orchestrator\TaskQueue;
use VoidLux\Swarm\Storage\SwarmDatabase;

/**
 * Coroutine loop that polls all local agents every 5 seconds.
 *
 * For agents without a task: checks tmux session liveness via AgentBridge.
 * If the session is dead (Status::Stopped), deregisters the agent and gossips
 * AGENT_DEREGISTER so all peers remove it. If alive, tries to auto-assign
 * the next pending task.
 *
 * For agents with a task: detects completion, errors, progress, and death.
 * Dead agents' tasks are requeued to pending (not failed) so another agent
 * can claim them.
 *
 * Wellness check: on-demand roll call that verifies all local agents and
 * returns a report of alive vs pruned. Also runs at startup.
 */
class AgentMonitor
{
    private const POLL_INTERVAL = 5;
    private bool $running = false;

    /** @var callable|null fn(string $taskId, string $agentId, string $event, array $data): void */
    private $onEvent = null;

    public function __construct(
        private readonly SwarmDatabase $db,
        private readonly AgentBridge $bridge,
        private readonly TaskQueue $taskQueue,
        private readonly AgentRegistry $registry,
        private readonly string $nodeId,
    ) {}

    /**
     * Set event callback for task/agent state changes.
     * @param callable fn(string $taskId, string $agentId, string $event, array $data): void
     */
    public function onEvent(callable $callback): void
    {
        $this->onEvent = $callback;
    }

    public function start(): void
    {
        $this->running = true;

        Coroutine::create(function () {
            while ($this->running) {
                Coroutine::sleep(self::POLL_INTERVAL);
                $this->pollAgents();
            }
        });
    }

    private function pollAgents(): void
    {
        $agents = $this->db->getLocalAgents($this->nodeId);

        foreach ($agents as $agent) {
            if (!$agent->currentTaskId) {
                // Check if the agent's session is still alive before trying to assign
                $bridgeStatus = $this->bridge->detectStatus($agent);
                if ($bridgeStatus === Status::Stopped) {
                    $this->registry->deregister($agent->id);
                    $this->emit('', $agent->id, 'agent_stopped', ['name' => $agent->name]);
                    continue;
                }
                $this->tryAutoAssign($agent);
                continue;
            }

            $task = $this->db->getTask($agent->currentTaskId);
            if (!$task || $task->status->isTerminal()) {
                // Task gone or finished — mark agent idle
                $this->db->updateAgentStatus($agent->id, 'idle', null);
                $this->emit($agent->currentTaskId ?? '', $agent->id, 'agent_idle', []);
                $this->tryAutoAssign($agent);
                continue;
            }

            $status = $this->bridge->detectStatus($agent);
            $this->handleAgentStatus($agent, $task->id, $status);
        }
    }

    private function handleAgentStatus(AgentModel $agent, string $taskId, Status $status): void
    {
        switch ($status) {
            case Status::Running:
                $output = $this->bridge->captureOutput($agent, 10);
                $this->db->updateAgentStatus($agent->id, 'busy', $taskId);
                $this->taskQueue->updateProgress($taskId, $agent->id, $this->extractProgressLine($output));
                $this->emit($taskId, $agent->id, 'task_progress', ['output' => $output]);
                break;

            case Status::Idle:
                // Agent finished — extract result
                $output = $this->bridge->captureOutput($agent, 50);
                $result = $this->bridge->extractResult($output);
                $this->taskQueue->complete($taskId, $agent->id, $result);
                $this->db->updateAgentStatus($agent->id, 'idle', null);
                $this->emit($taskId, $agent->id, 'task_completed', ['result' => $result]);
                break;

            case Status::Error:
                $output = $this->bridge->captureOutput($agent, 30);
                $this->taskQueue->fail($taskId, $agent->id, $output);
                $this->db->updateAgentStatus($agent->id, 'idle', null);
                $this->emit($taskId, $agent->id, 'task_failed', ['error' => $output]);
                break;

            case Status::Waiting:
                $this->db->updateAgentStatus($agent->id, 'waiting', $taskId);
                $this->emit($taskId, $agent->id, 'agent_waiting', []);
                break;

            case Status::Stopped:
                $this->taskQueue->requeue($taskId, 'Agent session stopped');
                $this->registry->deregister($agent->id);
                $this->emit($taskId, $agent->id, 'agent_stopped', ['name' => $agent->name]);
                break;

            case Status::Starting:
                // Still starting up, wait
                break;
        }
    }

    private function tryAutoAssign(AgentModel $agent): void
    {
        if ($agent->status === 'offline') {
            return;
        }

        $bridgeStatus = $this->bridge->detectStatus($agent);
        if ($bridgeStatus !== Status::Idle) {
            return;
        }

        $task = $this->taskQueue->getNextPendingTask($agent->capabilities);
        if (!$task) {
            return;
        }

        $claimed = $this->taskQueue->claim($task->id, $agent->id);
        if (!$claimed) {
            return;
        }

        $this->db->updateAgentStatus($agent->id, 'busy', $task->id);

        $delivered = $this->bridge->deliverTask($agent, $task);
        if (!$delivered) {
            $this->taskQueue->fail($task->id, $agent->id, 'Failed to deliver task to tmux session');
            $this->db->updateAgentStatus($agent->id, 'idle', null);
        }

        $this->emit($task->id, $agent->id, 'task_assigned', ['title' => $task->title]);
    }

    private function extractProgressLine(string $output): ?string
    {
        $lines = array_filter(explode("\n", $output), fn($l) => trim($l) !== '');
        if (empty($lines)) {
            return null;
        }
        return trim(end($lines));
    }

    private function emit(string $taskId, string $agentId, string $event, array $data): void
    {
        if ($this->onEvent) {
            ($this->onEvent)($taskId, $agentId, $event, $data);
        }
    }

    /**
     * Wellness check: verify every local agent's tmux session is alive.
     * Prunes dead agents (deregister + requeue their tasks) and returns a report.
     *
     * @return array{alive: array, pruned: array}
     */
    public function wellnessCheck(): array
    {
        $agents = $this->db->getLocalAgents($this->nodeId);
        $alive = [];
        $pruned = [];

        foreach ($agents as $agent) {
            $status = $this->bridge->detectStatus($agent);

            if ($status === Status::Stopped) {
                $pruned[] = [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'session' => $agent->tmuxSessionId,
                    'had_task' => $agent->currentTaskId,
                ];
                $this->registry->deregister($agent->id);
                $this->emit(
                    $agent->currentTaskId ?? '',
                    $agent->id,
                    'agent_stopped',
                    ['name' => $agent->name, 'reason' => 'wellness_check'],
                );
                continue;
            }

            $alive[] = [
                'id' => $agent->id,
                'name' => $agent->name,
                'session' => $agent->tmuxSessionId,
                'status' => $status->value,
                'task' => $agent->currentTaskId,
            ];

            // Update DB status to reflect actual bridge state
            $dbStatus = match ($status) {
                Status::Idle => 'idle',
                Status::Running => 'busy',
                Status::Waiting => 'waiting',
                Status::Starting => $agent->status,
                default => $agent->status,
            };
            if ($dbStatus !== $agent->status) {
                $this->db->updateAgentStatus($agent->id, $dbStatus, $agent->currentTaskId);
            }
        }

        return ['alive' => $alive, 'pruned' => $pruned];
    }

    public function stop(): void
    {
        $this->running = false;
    }
}
