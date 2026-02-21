<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Orchestrator;

use Swoole\Coroutine;
use VoidLux\Swarm\Agent\AgentBridge;
use VoidLux\Swarm\Model\AgentModel;
use VoidLux\Swarm\Model\TaskModel;
use VoidLux\Swarm\Model\TaskStatus;
use VoidLux\Swarm\Storage\SwarmDatabase;
use VoidLux\Swarm\SwarmWebSocketHandler;

/**
 * Periodic emperor health check coroutine.
 *
 * Runs every 5 minutes, diagnoses swarm state, takes corrective action,
 * and pushes a summary to the dashboard via WebSocket.
 */
class SwarmOverseer
{
    private const CHECK_INTERVAL = 300; // 5 min
    private const STALE_PLANNING_MINUTES = 10;
    private const LONG_RUNNING_MINUTES = 30;
    private const INITIAL_DELAY = 60;

    private bool $running = false;

    public function __construct(
        private readonly SwarmDatabase $db,
        private readonly TaskQueue $taskQueue,
        private readonly AgentBridge $bridge,
        private readonly TaskDispatcher $dispatcher,
        private readonly ?SwarmWebSocketHandler $wsHandler,
        private readonly string $nodeId,
    ) {}

    /**
     * Start the periodic overseer loop. Runs in a coroutine.
     */
    public function start(): void
    {
        $this->running = true;

        // Initial delay — let the swarm stabilize after boot
        Coroutine::sleep(self::INITIAL_DELAY);

        while ($this->running) {
            try {
                $report = $this->runCheck();
                $actions = $report['actions_taken'] ?? 0;
                $this->log("Overseer check complete: {$actions} corrective action(s)");
            } catch (\Throwable $e) {
                $this->log("Overseer error: {$e->getMessage()}");
            }

            Coroutine::sleep(self::CHECK_INTERVAL);
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Run all health checks, take corrective action, push report to WS.
     * Can be called on-demand via the API endpoint.
     *
     * @return array The overseer report
     */
    public function runCheck(): array
    {
        $report = [
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'node_id' => $this->nodeId,
            'findings' => [],
            'actions_taken' => 0,
            'agent_summary' => [],
            'planner_output' => [],
        ];

        $report['actions_taken'] += $this->checkStuckParents($report);
        $report['actions_taken'] += $this->checkStalePlanningTasks($report);
        $report['actions_taken'] += $this->checkFailedSubtasks($report);
        $report['actions_taken'] += $this->checkDispatchMismatch($report);
        $this->checkLongRunningTasks($report);
        $this->capturePlannerOutput($report);
        $this->getAgentSummary($report);

        $this->pushReport($report);

        return $report;
    }

    /**
     * Check for parent tasks in `in_progress` where ALL subtasks are terminal.
     * Calls tryCompleteParent() to fix them.
     */
    private function checkStuckParents(array &$report): int
    {
        $actions = 0;
        $inProgressTasks = $this->db->getTasksByStatus('in_progress');

        foreach ($inProgressTasks as $task) {
            $subtasks = $this->db->getSubtasks($task->id);
            if (empty($subtasks)) {
                continue;
            }

            $allTerminal = true;
            foreach ($subtasks as $sub) {
                if (!$sub->status->isTerminal()) {
                    $allTerminal = false;
                    break;
                }
            }

            if ($allTerminal) {
                $report['findings'][] = [
                    'type' => 'stuck_parent',
                    'task_id' => $task->id,
                    'title' => $task->title,
                    'subtask_count' => count($subtasks),
                    'action' => 'tryCompleteParent',
                ];
                $this->taskQueue->tryCompleteParent($task->id);
                $actions++;
            }
        }

        return $actions;
    }

    /**
     * Check for tasks stuck in `planning` status claimed >10 min ago.
     * Requeues them so they can be re-planned.
     */
    private function checkStalePlanningTasks(array &$report): int
    {
        $actions = 0;
        $planningTasks = $this->db->getTasksByStatus('planning');
        $staleThreshold = time() - (self::STALE_PLANNING_MINUTES * 60);

        foreach ($planningTasks as $task) {
            if ($task->claimedAt === null) {
                continue;
            }

            $claimedTs = strtotime($task->claimedAt);
            if ($claimedTs === false || $claimedTs > $staleThreshold) {
                continue;
            }

            $staleMins = round((time() - $claimedTs) / 60, 1);
            $report['findings'][] = [
                'type' => 'stale_planning',
                'task_id' => $task->id,
                'title' => $task->title,
                'stale_minutes' => $staleMins,
                'action' => 'requeue',
            ];
            $this->taskQueue->requeue($task->id, "Overseer: stale planning task ({$staleMins}min)");
            $actions++;
        }

        return $actions;
    }

    /**
     * Check for failed subtasks that haven't exhausted their retry budget.
     * Requeues them for another attempt.
     */
    private function checkFailedSubtasks(array &$report): int
    {
        $actions = 0;
        $failedTasks = $this->db->getTasksByStatus('failed');

        foreach ($failedTasks as $task) {
            if ($task->parentId === null || $task->parentId === '') {
                continue;
            }

            $rejectionCount = substr_count($task->reviewFeedback ?? '', '[Rejection');
            if ($rejectionCount >= 3) {
                continue;
            }

            $parent = $this->db->getTask($task->parentId);
            if (!$parent || $parent->status->isTerminal()) {
                continue;
            }

            $report['findings'][] = [
                'type' => 'retryable_failed_subtask',
                'task_id' => $task->id,
                'title' => $task->title,
                'parent_id' => $task->parentId,
                'rejection_count' => $rejectionCount,
                'action' => 'requeue',
            ];
            $this->taskQueue->requeue($task->id, "Overseer: retrying failed subtask (attempt " . ($rejectionCount + 1) . ")");
            $actions++;
        }

        return $actions;
    }

    /**
     * Check for idle agents + pending tasks that haven't been matched.
     * Forces triggerDispatch() to wake the dispatcher.
     */
    private function checkDispatchMismatch(array &$report): int
    {
        $idleAgents = array_values(array_filter(
            $this->db->getAllIdleAgents(),
            fn(AgentModel $a) => $a->role !== 'planner',
        ));
        $pendingTasks = $this->db->getTasksByStatus('pending');

        if (!empty($idleAgents) && !empty($pendingTasks)) {
            $report['findings'][] = [
                'type' => 'dispatch_mismatch',
                'idle_agents' => count($idleAgents),
                'pending_tasks' => count($pendingTasks),
                'action' => 'triggerDispatch',
            ];
            $this->dispatcher->triggerDispatch();
            return 1;
        }

        return 0;
    }

    /**
     * Flag agents that have been busy >30 min without progress.
     * Warning only — no auto-kill.
     */
    private function checkLongRunningTasks(array &$report): void
    {
        $longThreshold = time() - (self::LONG_RUNNING_MINUTES * 60);
        $activeTasks = array_merge(
            $this->db->getTasksByStatus('claimed'),
            $this->db->getTasksByStatus('in_progress'),
        );

        foreach ($activeTasks as $task) {
            if ($task->claimedAt === null) {
                continue;
            }

            $claimedTs = strtotime($task->claimedAt);
            if ($claimedTs === false || $claimedTs > $longThreshold) {
                continue;
            }

            $runningMins = round((time() - $claimedTs) / 60, 1);
            $report['findings'][] = [
                'type' => 'long_running',
                'task_id' => $task->id,
                'title' => $task->title,
                'agent_id' => $task->assignedTo,
                'running_minutes' => $runningMins,
                'action' => 'flagged_only',
            ];
        }
    }

    /**
     * Capture last 30 lines from each planner agent's tmux pane.
     */
    private function capturePlannerOutput(array &$report): void
    {
        $localAgents = $this->db->getLocalAgents($this->nodeId);

        foreach ($localAgents as $agent) {
            if ($agent->role !== 'planner') {
                continue;
            }

            $output = $this->bridge->captureOutput($agent, 30);
            $report['planner_output'][] = [
                'agent_id' => $agent->id,
                'agent_name' => $agent->name,
                'status' => $agent->status,
                'output' => $output,
            ];
        }
    }

    /**
     * Build agent summary: counts by status, busy agents with task info.
     */
    private function getAgentSummary(array &$report): void
    {
        $allAgents = $this->db->getAllAgents();
        $counts = ['idle' => 0, 'busy' => 0, 'starting' => 0, 'offline' => 0];
        $busyAgents = [];

        foreach ($allAgents as $agent) {
            $status = $agent->status;
            $counts[$status] = ($counts[$status] ?? 0) + 1;

            if ($status === 'busy' && $agent->currentTaskId) {
                $task = $this->db->getTask($agent->currentTaskId);
                $duration = null;
                if ($task && $task->claimedAt) {
                    $claimedTs = strtotime($task->claimedAt);
                    if ($claimedTs !== false) {
                        $duration = round((time() - $claimedTs) / 60, 1);
                    }
                }
                $busyAgents[] = [
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->name,
                    'role' => $agent->role,
                    'node_id' => substr($agent->nodeId, 0, 8),
                    'task_id' => $agent->currentTaskId,
                    'task_title' => $task?->title ?? 'unknown',
                    'duration_minutes' => $duration,
                ];
            }
        }

        $report['agent_summary'] = [
            'counts' => $counts,
            'total' => count($allAgents),
            'busy_agents' => $busyAgents,
        ];
    }

    /**
     * Push the overseer report to all WS-connected dashboard clients.
     */
    private function pushReport(array $report): void
    {
        $this->wsHandler?->pushStatus(['overseer_report' => $report]);
    }

    private function log(string $message): void
    {
        $short = substr($this->nodeId, 0, 8);
        $time = date('H:i:s');
        echo "[{$time}][{$short}][overseer] {$message}\n";
    }
}
