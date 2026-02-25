<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Orchestrator;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use VoidLux\P2P\Protocol\LamportClock;
use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\TcpMesh;
use VoidLux\Swarm\Agent\AgentBridge;
use VoidLux\Swarm\Git\GitWorkspace;
use VoidLux\Swarm\Model\AgentModel;
use VoidLux\Swarm\Model\TaskModel;
use VoidLux\Swarm\Model\TaskStatus;
use VoidLux\Swarm\Forum\ForumOrchestrator;
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
    private ?AgentBridge $agentBridge = null;
    private ?OverflowDelegator $overflowDelegator = null;
    private ?ForumOrchestrator $forumOrchestrator = null;

    public function __construct(
        private readonly SwarmDatabase $db,
        private readonly TcpMesh $mesh,
        private readonly TaskQueue $taskQueue,
        private readonly LamportClock $clock,
        private readonly string $nodeId,
    ) {}

    public function setAgentBridge(AgentBridge $bridge): void
    {
        $this->agentBridge = $bridge;
    }

    public function setOverflowDelegator(OverflowDelegator $delegator): void
    {
        $this->overflowDelegator = $delegator;
    }

    public function setForumOrchestrator(ForumOrchestrator $forum): void
    {
        $this->forumOrchestrator = $forum;
    }

    public function getOverflowDelegator(): ?OverflowDelegator
    {
        return $this->overflowDelegator;
    }

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
        // Phase 0: Route new pending tasks through discussion if persona agents exist
        $this->routeNewTasksToDiscussion();

        // Phase 0b: Check active discussions for vote results or timeout
        $this->checkActiveDiscussions();

        // Phase 1: Cascade-fail blocked tasks whose dependencies failed
        $this->failBlockedWithFailedDeps();

        // Phase 2: Unblock tasks whose dependencies are now satisfied
        $this->unblockReadyTasks();

        // Phase 3: Dispatch planning tasks to idle planner agents
        $this->dispatchPlanningTasks();

        // Phase 4: Dispatch pending tasks to idle worker agents
        $pendingTasks = $this->db->getTasksByStatus('pending');
        if (empty($pendingTasks)) {
            return;
        }

        // Get idle agents, excluding planner-role agents (they only handle planning)
        $idleAgents = array_values(array_filter(
            $this->db->getAllIdleAgents(),
            fn(AgentModel $a) => $a->role !== 'planner',
        ));

        // If no local agents, try marketplace delegation directly
        if (empty($idleAgents)) {
            $this->delegateOverflow($pendingTasks);
            return;
        }

        $undispatched = [];

        foreach ($pendingTasks as $task) {
            if (empty($idleAgents)) {
                $undispatched[] = $task;
                continue;
            }

            // Defensive: skip pending tasks with unmet dependencies
            if (!empty($task->dependsOn) && !$this->areDependenciesSatisfied($task)) {
                continue;
            }

            $agent = $this->selectAgent($task, $idleAgents);
            if (!$agent) {
                $undispatched[] = $task;
                continue;
            }

            $dispatched = $this->dispatchTask($task, $agent);
            if ($dispatched) {
                // Remove agent from idle pool
                $idleAgents = array_values(array_filter(
                    $idleAgents,
                    fn(AgentModel $a) => $a->id !== $agent->id,
                ));
            } else {
                $undispatched[] = $task;
            }
        }

        // Delegate remaining undispatched tasks to the marketplace
        if (!empty($undispatched)) {
            $this->delegateOverflow($undispatched);
        }
    }

    /**
     * Attempt to delegate overflow tasks to remote swarms via the broker.
     * @param TaskModel[] $tasks
     */
    private function delegateOverflow(array $tasks): void
    {
        if ($this->overflowDelegator === null) {
            return;
        }
        $this->overflowDelegator->delegateOverflow($tasks);
    }

    /**
     * Route new pending top-level tasks (no parentId) to discussion if persona agents exist.
     * Skips if no ForumOrchestrator or no persona agents registered.
     */
    private function routeNewTasksToDiscussion(): void
    {
        if (!$this->forumOrchestrator) {
            return;
        }

        $personaAgents = $this->forumOrchestrator->getPersonaAgents();
        if (empty($personaAgents)) {
            return; // No persona agents — skip discussion phase
        }

        $pendingTasks = $this->db->getTasksByStatus('pending');
        foreach ($pendingTasks as $task) {
            // Only top-level tasks (not subtasks) enter discussion
            if ($task->parentId) {
                continue;
            }

            // Transition to Discussing
            $updated = $task->withStatus(TaskStatus::Discussing, $this->clock->tick());
            $this->db->updateTask($updated);

            // Start discussion channel
            $this->forumOrchestrator->startDiscussion($updated);

            // Deliver prompts to idle persona agents
            $idlePersonas = $this->forumOrchestrator->getIdlePersonaAgents();
            if (!empty($idlePersonas)) {
                $this->forumOrchestrator->deliverDiscussionPrompts($updated, $idlePersonas);
            }
        }
    }

    /**
     * Check active discussions for vote majority or timeout.
     * Advances approved tasks to Planning, rejected to WaitingInput.
     */
    private function checkActiveDiscussions(): void
    {
        if (!$this->forumOrchestrator) {
            return;
        }

        // Check pre-implementation discussions
        $discussingTasks = $this->db->getTasksByStatus('discussing');
        foreach ($discussingTasks as $task) {
            $channelId = $task->id;
            $result = $this->forumOrchestrator->checkVotes($channelId);

            if ($result === null && !$this->forumOrchestrator->isTimedOut($channelId)) {
                continue;
            }

            if ($result === 'approve' || $result === null) {
                $updated = $task->withStatus(TaskStatus::Planning, $this->clock->tick());
                $this->db->updateTask($updated);
                $this->forumOrchestrator->resolveChannel($channelId, $result ?? 'timeout');
            } elseif ($result === 'reject') {
                $updated = $task->withStatus(TaskStatus::WaitingInput, $this->clock->tick());
                $this->db->updateTask($updated);
                $this->forumOrchestrator->resolveChannel($channelId, 'reject');
            }
        }

        // Check QA review discussions (review:{taskId} channels)
        $reviewTasks = $this->db->getTasksByStatus('pending_review');
        foreach ($reviewTasks as $task) {
            // Only check parent tasks in review that have a review channel
            if (!$task->parentId) {
                // This is a parent task — check its review channel
                $reviewChannel = "review:{$task->id}";
                $result = $this->forumOrchestrator->checkVotes($reviewChannel);

                if ($result === null && !$this->forumOrchestrator->isTimedOut($reviewChannel)) {
                    continue;
                }

                if ($result === 'approve' || $result === null) {
                    // QA approved — complete the parent task
                    $ts = $this->clock->tick();
                    $now = gmdate('Y-m-d\TH:i:s\Z');
                    $completedResult = $task->progress ?: 'QA review approved';
                    $updated = $task->withStatus(TaskStatus::Completed, $ts);
                    $this->db->updateTask($updated);
                    $this->db->setTaskResult($task->id, $completedResult, $now);
                    $this->forumOrchestrator->resolveChannel($reviewChannel, $result ?? 'timeout');

                    // Auto-merge if flagged
                    if ($task->autoMerge && $task->prUrl) {
                        $git = new \VoidLux\Swarm\Git\GitWorkspace();
                        $mergeWorkDir = getcwd() . '/workbench/.merge';
                        $git->autoMergePullRequest($mergeWorkDir, $task->prUrl);
                    }
                } elseif ($result === 'reject') {
                    // QA rejected — requeue subtasks for rework
                    $updated = $task->withStatus(TaskStatus::InProgress, $this->clock->tick());
                    $this->db->updateTask($updated);
                    $this->forumOrchestrator->resolveChannel($reviewChannel, 'reject');
                    // Requeue all subtasks
                    $subtasks = $this->db->getSubtasks($task->id);
                    foreach ($subtasks as $sub) {
                        if ($sub->status === TaskStatus::Completed) {
                            $requeuedSub = $sub->withStatus(TaskStatus::Pending, $this->clock->tick());
                            $this->db->updateTask($requeuedSub);
                        }
                    }
                }
            }
        }
    }

    /**
     * Dispatch unassigned Planning tasks to idle planner agents.
     * When a planner agent goes idle and there are queued planning tasks,
     * this assigns the next one.
     */
    private function dispatchPlanningTasks(): void
    {
        $planningTasks = $this->db->getTasksByStatus('planning');
        if (empty($planningTasks)) {
            return;
        }

        // Only dispatch unassigned planning tasks
        $unassigned = array_filter($planningTasks, fn(TaskModel $t) => $t->assignedTo === null || $t->assignedTo === '');
        if (empty($unassigned)) {
            return;
        }

        $plannerAgent = $this->db->getIdlePlannerAgent();
        if (!$plannerAgent) {
            return;
        }

        // Dispatch one planning task at a time (planner processes sequentially)
        $task = reset($unassigned);

        // Local delivery only (planner is always local)
        if ($plannerAgent->nodeId !== $this->nodeId || !$this->agentBridge) {
            return;
        }

        $claimed = $this->taskQueue->claim($task->id, $plannerAgent->id);
        if (!$claimed) {
            return;
        }

        $this->db->updateAgentStatus($plannerAgent->id, 'busy', $task->id);
        $task = $this->db->getTask($task->id); // Re-read after claim

        if ($task) {
            $delivered = $this->agentBridge->deliverPlanningTask($plannerAgent, $task);
            if (!$delivered) {
                $this->taskQueue->requeue($task->id, 'Planner agent not ready');
                $this->db->updateAgentStatus($plannerAgent->id, 'idle', null);
                return;
            }
            $this->taskQueue->updateProgress($task->id, $plannerAgent->id, 'Planner agent analyzing codebase');
        }
    }

    /**
     * Promote Blocked → Pending for tasks whose dependencies all completed.
     */
    private function unblockReadyTasks(): void
    {
        $ready = $this->db->getUnblockedTasks();
        foreach ($ready as $task) {
            $this->taskQueue->unblockTask($task->id);
        }
    }

    /**
     * Fail blocked tasks whose dependencies have failed or been cancelled.
     */
    private function failBlockedWithFailedDeps(): void
    {
        $doomed = $this->db->getBlockedTasksWithFailedDeps();
        foreach ($doomed as $task) {
            $this->taskQueue->fail($task->id, '', 'Dependency failed or cancelled');
            if ($task->parentId) {
                $this->taskQueue->tryCompleteParent($task->parentId);
            }
        }
    }

    /**
     * Check if all dependencies for a pending task are completed.
     */
    private function areDependenciesSatisfied(TaskModel $task): bool
    {
        foreach ($task->dependsOn as $depId) {
            $dep = $this->db->getTask($depId);
            if (!$dep || $dep->status !== TaskStatus::Completed) {
                return false;
            }
        }
        return true;
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
            $git = new GitWorkspace();
            if ($git->isGitUrl($task->projectPath)) {
                // Task is a git URL — prefer agents that have a local worktree directory
                $affinityAgents = array_filter(
                    $eligible,
                    fn(AgentModel $a) => $a->projectPath && is_dir($a->projectPath),
                );
            } else {
                $affinityAgents = array_filter(
                    $eligible,
                    fn(AgentModel $a) => $a->projectPath === $task->projectPath,
                );
            }
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
            // If the agent is local, claim and deliver directly
            if ($agent->nodeId === $this->nodeId) {
                $claimed = $this->taskQueue->claim($task->id, $agent->id);
                if (!$claimed) {
                    return false;
                }
                $this->db->updateAgentStatus($agent->id, 'busy', $task->id);
                $task = $this->db->getTask($task->id); // Re-read after claim
                if ($task && $this->agentBridge) {
                    $delivered = $this->agentBridge->deliverTask($agent, $task);
                    if (!$delivered) {
                        $this->taskQueue->requeue($task->id, 'Local delivery failed');
                        $this->db->updateAgentStatus($agent->id, 'idle', null);
                        return false;
                    }
                    // Transition claimed → in_progress after successful delivery
                    $this->taskQueue->updateProgress($task->id, $agent->id, null);
                }
                return true;
            }
            return false;
        }

        // Optimistic claim in local DB — mark both task and agent
        $this->db->claimTask($task->id, $agent->id, $agent->nodeId, $ts);
        $this->db->updateAgentStatus($agent->id, 'busy', $task->id);
        return true;
    }

    public function stop(): void
    {
        $this->running = false;
        $this->signal?->push(false, 0); // Wake the loop so it exits
        $this->signal?->close();
    }
}
