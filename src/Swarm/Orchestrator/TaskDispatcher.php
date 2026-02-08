<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Orchestrator;

use Swoole\Coroutine;
use VoidLux\P2P\Protocol\LamportClock;
use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\TcpMesh;
use VoidLux\Swarm\Model\AgentModel;
use VoidLux\Swarm\Model\TaskModel;
use VoidLux\Swarm\Model\TaskStatus;
use VoidLux\Swarm\Storage\SwarmDatabase;

/**
 * Push-based task dispatcher. Runs on the emperor node.
 * Scans pending tasks, matches them to idle agents, and sends TASK_ASSIGN
 * directly to the target worker via P2P mesh.
 */
class TaskDispatcher
{
    private const POLL_INTERVAL = 3;
    private const DEBOUNCE_MS = 100;

    private bool $running = false;
    private int $roundRobinIndex = 0;
    private bool $triggerPending = false;

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

        // Main dispatch loop
        Coroutine::create(function () {
            while ($this->running) {
                $this->dispatchAll();
                Coroutine::sleep(self::POLL_INTERVAL);
            }
        });

        // Trigger listener (coalesced)
        Coroutine::create(function () {
            while ($this->running) {
                Coroutine::sleep(0.1);
                if ($this->triggerPending) {
                    $this->triggerPending = false;
                    Coroutine::sleep(self::DEBOUNCE_MS / 1000);
                    if ($this->running) {
                        $this->dispatchAll();
                    }
                }
            }
        });
    }

    /**
     * Request an immediate dispatch cycle (debounced).
     */
    public function triggerDispatch(): void
    {
        $this->triggerPending = true;
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
        // Filter by capabilities
        $eligible = [];
        foreach ($agents as $agent) {
            if (!empty($task->requiredCapabilities)) {
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
    }
}
