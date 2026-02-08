<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Agent;

use Aoe\Session\Status;
use Swoole\Coroutine;
use VoidLux\Swarm\Leadership\LeaderElection;
use VoidLux\Swarm\Model\AgentModel;
use VoidLux\Swarm\Model\TaskStatus;
use VoidLux\Swarm\Orchestrator\TaskDispatcher;
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
    private ?LeaderElection $leaderElection = null;
    private ?TaskDispatcher $taskDispatcher = null;

    /** @var callable|null fn(string $taskId, string $agentId, string $event, array $data): void */
    private $onEvent = null;

    public function __construct(
        private readonly SwarmDatabase $db,
        private readonly AgentBridge $bridge,
        private readonly TaskQueue $taskQueue,
        private readonly AgentRegistry $registry,
        private readonly string $nodeId,
    ) {}

    public function setLeaderElection(LeaderElection $election): void
    {
        $this->leaderElection = $election;
    }

    public function setTaskDispatcher(TaskDispatcher $dispatcher): void
    {
        $this->taskDispatcher = $dispatcher;
    }

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

        // Build a set of agent IDs that currently own a task
        $busyAgentIds = [];
        foreach ($agents as $agent) {
            if ($agent->currentTaskId) {
                $busyAgentIds[$agent->id] = $agent->currentTaskId;
            }
        }

        // Requeue stale claimed tasks: assigned to a local agent that no longer owns them
        $staleTasks = $this->db->getOrphanedTasks($this->nodeId);
        foreach ($staleTasks as $task) {
            if ($task->assignedTo && !isset($busyAgentIds[$task->assignedTo])) {
                $this->taskQueue->requeue($task->id, 'Agent no longer owns task');
                $this->emit($task->id, $task->assignedTo ?? '', 'task_requeued', ['title' => $task->title]);
            }
        }

        foreach ($agents as $agent) {
            // Starting agents: wait for Claude Code to load, then flip to idle
            if ($agent->status === 'starting') {
                $registeredAgo = time() - strtotime($agent->registeredAt);
                if ($registeredAgo < 10) {
                    continue; // Too early — Claude Code still loading
                }
                $bridgeStatus = $this->bridge->detectStatus($agent);
                if ($bridgeStatus === Status::Idle) {
                    $this->db->updateAgentStatus($agent->id, 'idle', null);
                    // Re-read agent with updated status and gossip immediately
                    $updatedAgent = $this->db->getAgent($agent->id);
                    if ($updatedAgent) {
                        $this->registry->gossipAgentNow($updatedAgent);
                    }
                    $this->emit('', $agent->id, 'agent_ready', ['name' => $agent->name]);
                    $this->taskDispatcher?->triggerDispatch();
                } elseif ($bridgeStatus === Status::Stopped) {
                    $this->registry->deregister($agent->id);
                    $this->emit('', $agent->id, 'agent_stopped', ['name' => $agent->name]);
                }
                continue;
            }

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

            // MCP manages waiting_input tasks — skip pane polling
            if ($task->status === TaskStatus::WaitingInput) {
                continue;
            }

            // Grace period: don't poll a task that was just claimed (prompt still being delivered)
            if ($task->claimedAt) {
                $claimedAgo = time() - strtotime($task->claimedAt);
                if ($claimedAgo < 10) {
                    continue;
                }
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
                // Agent is at the prompt while owning a task.
                // Do NOT auto-complete — wait for the agent to call task_complete via MCP.
                // If the agent has been idle for a long time, the prompt was likely never
                // delivered or the agent finished without calling MCP. Requeue the task.
                $task = $this->db->getTask($taskId);
                if ($task && $task->claimedAt) {
                    $claimedAgo = time() - strtotime($task->claimedAt);
                    if ($claimedAgo > 120) {
                        // 2 minutes idle with a task = delivery failure, requeue
                        $this->taskQueue->requeue($taskId, 'Agent idle too long — delivery likely failed');
                        $this->db->updateAgentStatus($agent->id, 'idle', null);
                        $this->emit($taskId, $agent->id, 'task_requeued', ['reason' => 'idle_timeout']);
                    }
                }
                break;

            case Status::Error:
                // Do NOT auto-fail — only MCP task_failed should mark tasks as failed.
                // The agent may recover, or the StatusDetector may be misreading the pane.
                $this->db->updateAgentStatus($agent->id, 'busy', $taskId);
                $this->emit($taskId, $agent->id, 'task_progress', ['output' => 'Agent pane shows error state']);
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

        // When emperor is alive, it handles dispatch via push model
        if ($this->leaderElection !== null && $this->leaderElection->isEmperorAlive()) {
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
            // Requeue instead of failing — agent may still be starting up
            $this->taskQueue->requeue($task->id, 'Agent not ready for delivery');
            $this->db->updateAgentStatus($agent->id, 'idle', null);
            return;
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
