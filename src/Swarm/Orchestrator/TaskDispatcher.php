<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Orchestrator;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use VoidLux\P2P\Protocol\LamportClock;
use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\TcpMesh;
use VoidLux\Swarm\Model\AgentModel;
use VoidLux\Swarm\Model\TaskModel;
use VoidLux\Swarm\Model\TaskStatus;
use VoidLux\Swarm\Storage\SwarmDatabase;

/**
 * Event-driven task dispatcher. Runs on the emperor node.
 *
 * Blocks on a Channel until signaled by triggerDispatch(), then matches
 * pending tasks to idle agents and sends TASK_ASSIGN via P2P mesh.
 * A 30-second heartbeat timeout acts as a safety net for missed events.
 */
class TaskDispatcher
{
    /** Safety-net interval — dispatch runs even without explicit trigger */
    private const HEARTBEAT_INTERVAL = 30;

    private bool $running = false;
    private int $roundRobinIndex = 0;
    private ?Channel $signal = null;

    public function __construct(
        private readonly SwarmDatabase $db,
        private readonly TcpMesh $mesh,
        private readonly TaskQueue $taskQueue,
        private readonly LamportClock $clock,
        private readonly string $nodeId,
    ) {}

    public function start(): void
    {
        $this->running = true;
        $this->signal = new Channel(1);

        // Single dispatch loop — blocks on channel, wakes on event or timeout
        Coroutine::create(function () {
            while ($this->running) {
                $this->signal->pop(self::HEARTBEAT_INTERVAL);
                if ($this->running) {
                    $this->dispatchAll();
                }
            }
        });
    }

    /**
     * Signal the dispatcher to run immediately.
     * Non-blocking: if a signal is already pending, this is a no-op (coalesced).
     */
    public function triggerDispatch(): void
    {
        $this->signal?->push(true, 0);
    }

    private function dispatchAll(): void
    {
        $pendingTasks = $this->db->getTasksByStatus('pending');
        if (empty($pendingTasks)) {
            return;
        }

        $idleAgents = $this->db->getAllIdleAgents();
        if (empty($idleAgents)) {
            return;
        }

        foreach ($pendingTasks as $task) {
            if (empty($idleAgents)) {
                break;
            }

            $agent = $this->selectAgent($task, $idleAgents);
            if (!$agent) {
                continue;
            }

            $dispatched = $this->dispatchTask($task, $agent);
            if ($dispatched) {
                // Remove agent from idle pool
                $idleAgents = array_values(array_filter(
                    $idleAgents,
                    fn(AgentModel $a) => $a->id !== $agent->id,
                ));
            }
        }
    }

    /**
     * Select the best idle agent for a task.
     */
    private function selectAgent(TaskModel $task, array $agents): ?AgentModel
    {
        // Filter by capabilities (empty agent capabilities = universal, can handle any task)
        $eligible = [];
        foreach ($agents as $agent) {
            if (!empty($task->requiredCapabilities) && !empty($agent->capabilities)) {
                $missing = array_diff($task->requiredCapabilities, $agent->capabilities);
                if (!empty($missing)) {
                    continue;
                }
            }
            $eligible[] = $agent;
        }

        if (empty($eligible)) {
            return null;
        }

        // Prefer agents with matching project path (affinity)
        if ($task->projectPath) {
            $affinityAgents = array_filter(
                $eligible,
                fn(AgentModel $a) => $a->projectPath === $task->projectPath,
            );
            if (!empty($affinityAgents)) {
                $eligible = array_values($affinityAgents);
            }
        }

        // Round-robin among eligible
        $index = $this->roundRobinIndex % count($eligible);
        $this->roundRobinIndex++;
        return $eligible[$index];
    }

    private function dispatchTask(TaskModel $task, AgentModel $agent): bool
    {
        $ts = $this->clock->tick();

        // Send TASK_ASSIGN to the agent's worker node
        $sent = $this->mesh->sendTo($agent->nodeId, [
            'type' => MessageTypes::TASK_ASSIGN,
            'task_id' => $task->id,
            'agent_id' => $agent->id,
            'node_id' => $agent->nodeId,
            'lamport_ts' => $ts,
        ]);

        if (!$sent) {
            // If the agent is local, claim directly
            if ($agent->nodeId === $this->nodeId) {
                return $this->taskQueue->claim($task->id, $agent->id);
            }
            return false;
        }

        // Optimistic claim in local DB
        $this->db->claimTask($task->id, $agent->id, $agent->nodeId, $ts);
        return true;
    }

    public function stop(): void
    {
        $this->running = false;
        $this->signal?->push(false, 0); // Wake the loop so it exits
        $this->signal?->close();
    }
}
