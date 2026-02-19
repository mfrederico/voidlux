<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Orchestrator;

use Swoole\Coroutine;
use VoidLux\P2P\Protocol\LamportClock;
use VoidLux\Swarm\Ai\TaskReviewer;
use VoidLux\Swarm\Git\GitWorkspace;
use VoidLux\Swarm\Gossip\TaskGossipEngine;
use VoidLux\Swarm\Model\TaskModel;
use VoidLux\Swarm\Model\TaskStatus;
use VoidLux\Swarm\Storage\SwarmDatabase;

/**
 * Task lifecycle management: create, claim, update, complete, fail, cancel.
 * Includes merge-test-retry loop for parent tasks with git branches.
 */
class TaskQueue
{
    private ?TaskReviewer $reviewer = null;
    private ?GitWorkspace $git = null;
    private string $globalTestCommand = '';
    private string $mergeWorkDir = '';
    private string $baseRepoDir = '';
    private ?TaskDispatcher $dispatcher = null;
    private const MAX_REJECTIONS = 3;

    public function __construct(
        private readonly SwarmDatabase $db,
        private readonly TaskGossipEngine $gossip,
        private readonly LamportClock $clock,
        private readonly string $nodeId,
    ) {}

    public function setReviewer(TaskReviewer $reviewer): void
    {
        $this->reviewer = $reviewer;
    }

    public function setGitWorkspace(GitWorkspace $git): void
    {
        $this->git = $git;
    }

    public function setGlobalTestCommand(string $cmd): void
    {
        $this->globalTestCommand = $cmd;
    }

    public function setMergeWorkDir(string $dir): void
    {
        $this->mergeWorkDir = $dir;
    }

    public function setBaseRepoDir(string $dir): void
    {
        $this->baseRepoDir = $dir;
    }

    public function setTaskDispatcher(TaskDispatcher $dispatcher): void
    {
        $this->dispatcher = $dispatcher;
    }

    public function createTask(
        string $title,
        string $description = '',
        int $priority = 0,
        array $requiredCapabilities = [],
        string $projectPath = '',
        string $context = '',
        string $createdBy = '',
        ?string $parentId = null,
        string $workInstructions = '',
        string $acceptanceCriteria = '',
        ?TaskStatus $status = null,
        string $testCommand = '',
        array $dependsOn = [],
        bool $autoMerge = false,
    ): TaskModel {
        $ts = $this->clock->tick();

        // If dependencies are specified and no explicit status, start as Blocked
        if (!empty($dependsOn) && $status === null) {
            $status = TaskStatus::Blocked;
        }

        $task = TaskModel::create(
            title: $title,
            description: $description,
            createdBy: $createdBy ?: $this->nodeId,
            lamportTs: $ts,
            priority: $priority,
            requiredCapabilities: $requiredCapabilities,
            projectPath: $projectPath,
            context: $context,
            parentId: $parentId,
            workInstructions: $workInstructions,
            acceptanceCriteria: $acceptanceCriteria,
            status: $status,
            testCommand: $testCommand,
            dependsOn: $dependsOn,
            autoMerge: $autoMerge,
        );

        return $this->gossip->createTask($task);
    }

    /**
     * Transition a blocked task to pending when its dependencies are met.
     */
    public function unblockTask(string $taskId): bool
    {
        $task = $this->db->getTask($taskId);
        if (!$task || $task->status !== TaskStatus::Blocked) {
            return false;
        }

        $ts = $this->clock->tick();
        $updated = new TaskModel(
            id: $task->id, title: $task->title, description: $task->description,
            status: TaskStatus::Pending, priority: $task->priority,
            requiredCapabilities: $task->requiredCapabilities, createdBy: $task->createdBy,
            assignedTo: $task->assignedTo, assignedNode: $task->assignedNode,
            result: $task->result, error: $task->error, progress: $task->progress,
            projectPath: $task->projectPath, context: $task->context,
            lamportTs: $ts, claimedAt: $task->claimedAt, completedAt: $task->completedAt,
            createdAt: $task->createdAt, updatedAt: gmdate('Y-m-d\TH:i:s\Z'),
            parentId: $task->parentId, workInstructions: $task->workInstructions,
            acceptanceCriteria: $task->acceptanceCriteria, reviewStatus: $task->reviewStatus,
            reviewFeedback: $task->reviewFeedback, archived: $task->archived,
            gitBranch: $task->gitBranch, mergeAttempts: $task->mergeAttempts,
            testCommand: $task->testCommand, dependsOn: $task->dependsOn,
            autoMerge: $task->autoMerge, prUrl: $task->prUrl,
        );
        $this->db->updateTask($updated);
        $this->gossip->gossipTaskUpdate($taskId, '', TaskStatus::Pending->value, null, $ts);
        return true;
    }

    /**
     * Attempt to claim a task for an agent on this node.
     * Returns true if the claim succeeded locally.
     */
    public function claim(string $taskId, string $agentId): bool
    {
        $ts = $this->clock->tick();
        $claimed = $this->db->claimTask($taskId, $agentId, $this->nodeId, $ts);

        if ($claimed) {
            $this->gossip->gossipTaskClaim($taskId, $agentId, $this->nodeId, $ts);
        }

        return $claimed;
    }

    public function updateProgress(string $taskId, string $agentId, ?string $progress): void
    {
        $ts = $this->clock->tick();

        $task = $this->db->getTask($taskId);
        if (!$task || $task->status->isTerminal() || !$task->status->isWorkableByAgent()) {
            return;
        }

        $updated = new TaskModel(
            id: $task->id,
            title: $task->title,
            description: $task->description,
            status: TaskStatus::InProgress,
            priority: $task->priority,
            requiredCapabilities: $task->requiredCapabilities,
            createdBy: $task->createdBy,
            assignedTo: $task->assignedTo,
            assignedNode: $task->assignedNode,
            result: $task->result,
            error: $task->error,
            progress: $progress,
            projectPath: $task->projectPath,
            context: $task->context,
            lamportTs: $ts,
            claimedAt: $task->claimedAt,
            completedAt: $task->completedAt,
            createdAt: $task->createdAt,
            updatedAt: gmdate('Y-m-d\TH:i:s\Z'),
            parentId: $task->parentId,
            workInstructions: $task->workInstructions,
            acceptanceCriteria: $task->acceptanceCriteria,
            reviewStatus: $task->reviewStatus,
            reviewFeedback: $task->reviewFeedback,
            archived: $task->archived,
            gitBranch: $task->gitBranch,
            mergeAttempts: $task->mergeAttempts,
            testCommand: $task->testCommand,
            dependsOn: $task->dependsOn,
            autoMerge: $task->autoMerge,
            prUrl: $task->prUrl,
        );
        // Atomic CAS: only transition if still in expected agent-workable state
        $transitioned = $this->db->transitionTask($updated, [
            TaskStatus::Claimed,
            TaskStatus::InProgress,
            TaskStatus::WaitingInput,
        ]);
        if (!$transitioned) {
            return;
        }
        $this->gossip->gossipTaskUpdate($taskId, $agentId, TaskStatus::InProgress->value, $progress, $ts);
    }

    public function setWaitingInput(string $taskId, string $agentId, string $question): void
    {
        $ts = $this->clock->tick();

        $task = $this->db->getTask($taskId);
        if (!$task || $task->status->isTerminal() || !$task->status->isWorkableByAgent()) {
            return;
        }

        $updated = new TaskModel(
            id: $task->id,
            title: $task->title,
            description: $task->description,
            status: TaskStatus::WaitingInput,
            priority: $task->priority,
            requiredCapabilities: $task->requiredCapabilities,
            createdBy: $task->createdBy,
            assignedTo: $task->assignedTo,
            assignedNode: $task->assignedNode,
            result: $task->result,
            error: null,
            progress: $question,
            projectPath: $task->projectPath,
            context: $task->context,
            lamportTs: $ts,
            claimedAt: $task->claimedAt,
            completedAt: $task->completedAt,
            createdAt: $task->createdAt,
            updatedAt: gmdate('Y-m-d\TH:i:s\Z'),
            parentId: $task->parentId,
            workInstructions: $task->workInstructions,
            acceptanceCriteria: $task->acceptanceCriteria,
            reviewStatus: $task->reviewStatus,
            reviewFeedback: $task->reviewFeedback,
            archived: $task->archived,
            gitBranch: $task->gitBranch,
            mergeAttempts: $task->mergeAttempts,
            testCommand: $task->testCommand,
            dependsOn: $task->dependsOn,
            autoMerge: $task->autoMerge,
            prUrl: $task->prUrl,
        );
        // Atomic CAS: only transition if still in expected agent-workable state
        $transitioned = $this->db->transitionTask($updated, [
            TaskStatus::Claimed,
            TaskStatus::InProgress,
            TaskStatus::WaitingInput,
        ]);
        if (!$transitioned) {
            return;
        }
        $this->gossip->gossipTaskUpdate($taskId, $agentId, TaskStatus::WaitingInput->value, $question, $ts);
    }

    /**
     * @return bool True if the completion was processed, false if rejected (terminal/not found).
     */
    public function complete(string $taskId, string $agentId, ?string $result = null): bool
    {
        $ts = $this->clock->tick();

        $task = $this->db->getTask($taskId);
        if (!$task) {
            $this->log("complete() rejected: task {$taskId} not found");
            return false;
        }
        if ($task->status->isTerminal()) {
            $this->log("complete() rejected: task {$taskId} already in terminal state '{$task->status->value}'");
            return false;
        }
        if (!$task->status->isWorkableByAgent()) {
            $this->log("complete() rejected: task {$taskId} not workable from state '{$task->status->value}'");
            return false;
        }

        $allowedFromStatuses = [TaskStatus::Claimed, TaskStatus::InProgress, TaskStatus::WaitingInput];

        // If reviewer is configured and task has acceptance criteria, route to review
        if ($this->reviewer !== null && trim($task->acceptanceCriteria) !== '') {
            $updated = new TaskModel(
                id: $task->id,
                title: $task->title,
                description: $task->description,
                status: TaskStatus::PendingReview,
                priority: $task->priority,
                requiredCapabilities: $task->requiredCapabilities,
                createdBy: $task->createdBy,
                assignedTo: $agentId,
                assignedNode: $task->assignedNode,
                result: $result,
                error: null,
                progress: null,
                projectPath: $task->projectPath,
                context: $task->context,
                lamportTs: $ts,
                claimedAt: $task->claimedAt,
                completedAt: null,
                createdAt: $task->createdAt,
                updatedAt: gmdate('Y-m-d\TH:i:s\Z'),
                parentId: $task->parentId,
                workInstructions: $task->workInstructions,
                acceptanceCriteria: $task->acceptanceCriteria,
                reviewStatus: 'pending_review',
                reviewFeedback: $task->reviewFeedback,
                archived: $task->archived,
                gitBranch: $task->gitBranch,
                mergeAttempts: $task->mergeAttempts,
                testCommand: $task->testCommand,
                dependsOn: $task->dependsOn,
                autoMerge: $task->autoMerge,
                prUrl: $task->prUrl,
            );
            // Atomic CAS: only transition if still in agent-workable state
            $transitioned = $this->db->transitionTask($updated, $allowedFromStatuses);
            if (!$transitioned) {
                return false;
            }
            $this->gossip->gossipTaskUpdate($taskId, $agentId, TaskStatus::PendingReview->value, null, $ts);

            // Spawn review coroutine
            Coroutine::create(function () use ($taskId) {
                $this->reviewTask($taskId);
            });
            return true;
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $updated = new TaskModel(
            id: $task->id,
            title: $task->title,
            description: $task->description,
            status: TaskStatus::Completed,
            priority: $task->priority,
            requiredCapabilities: $task->requiredCapabilities,
            createdBy: $task->createdBy,
            assignedTo: $agentId,
            assignedNode: $task->assignedNode,
            result: $result,
            error: null,
            progress: null,
            projectPath: $task->projectPath,
            context: $task->context,
            lamportTs: $ts,
            claimedAt: $task->claimedAt,
            completedAt: $now,
            createdAt: $task->createdAt,
            updatedAt: $now,
            parentId: $task->parentId,
            workInstructions: $task->workInstructions,
            acceptanceCriteria: $task->acceptanceCriteria,
            reviewStatus: $task->reviewStatus,
            reviewFeedback: $task->reviewFeedback,
            archived: $task->archived,
            gitBranch: $task->gitBranch,
            mergeAttempts: $task->mergeAttempts,
            testCommand: $task->testCommand,
            dependsOn: $task->dependsOn,
            autoMerge: $task->autoMerge,
            prUrl: $task->prUrl,
        );
        // Atomic CAS: only transition if still in agent-workable state
        $transitioned = $this->db->transitionTask($updated, $allowedFromStatuses);
        if (!$transitioned) {
            return false;
        }
        $this->gossip->gossipTaskComplete($taskId, $agentId, $result, $ts);

        // Trigger dispatch to unblock dependent tasks
        $this->dispatcher?->triggerDispatch();

        // Check if all subtasks are done — complete the parent
        if ($task->parentId) {
            $this->tryCompleteParent($task->parentId);
        }

        return true;
    }

    /**
     * Complete a task that has already been reviewed and accepted (manual review path).
     * Skips the review check — goes straight to Completed + gossip + tryCompleteParent.
     */
    public function completeAccepted(string $taskId, string $agentId, ?string $result = null): void
    {
        $ts = $this->clock->tick();

        $task = $this->db->getTask($taskId);
        if (!$task || $task->status->isTerminal()) {
            return;
        }

        $updated = new TaskModel(
            id: $task->id, title: $task->title, description: $task->description,
            status: TaskStatus::Completed, priority: $task->priority,
            requiredCapabilities: $task->requiredCapabilities, createdBy: $task->createdBy,
            assignedTo: $agentId, assignedNode: $task->assignedNode,
            result: $result ?? $task->result, error: null, progress: null,
            projectPath: $task->projectPath, context: $task->context,
            lamportTs: $ts, claimedAt: $task->claimedAt,
            completedAt: gmdate('Y-m-d\TH:i:s\Z'),
            createdAt: $task->createdAt, updatedAt: gmdate('Y-m-d\TH:i:s\Z'),
            parentId: $task->parentId, workInstructions: $task->workInstructions,
            acceptanceCriteria: $task->acceptanceCriteria,
            reviewStatus: 'accepted', reviewFeedback: $task->reviewFeedback,
            archived: $task->archived, gitBranch: $task->gitBranch,
            mergeAttempts: $task->mergeAttempts, testCommand: $task->testCommand,
            dependsOn: $task->dependsOn,
            autoMerge: $task->autoMerge, prUrl: $task->prUrl,
        );
        $this->db->updateTask($updated);
        $this->gossip->gossipTaskComplete($taskId, $agentId, $result ?? $task->result, $ts);

        $this->dispatcher?->triggerDispatch();

        if ($task->parentId) {
            $this->tryCompleteParent($task->parentId);
        }
    }

    /**
     * Review a task using the AI reviewer.
     * Accepts or rejects with feedback. Rejected tasks get requeued (up to MAX_REJECTIONS).
     */
    public function reviewTask(string $taskId): void
    {
        $task = $this->db->getTask($taskId);
        if (!$task || !$this->reviewer || $task->status !== TaskStatus::PendingReview) {
            return;
        }

        $reviewResult = $this->reviewer->review($task, $task->result ?? '');
        $ts = $this->clock->tick();

        if ($reviewResult->accepted) {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $updated = new TaskModel(
                id: $task->id,
                title: $task->title,
                description: $task->description,
                status: TaskStatus::Completed,
                priority: $task->priority,
                requiredCapabilities: $task->requiredCapabilities,
                createdBy: $task->createdBy,
                assignedTo: $task->assignedTo,
                assignedNode: $task->assignedNode,
                result: $task->result,
                error: null,
                progress: null,
                projectPath: $task->projectPath,
                context: $task->context,
                lamportTs: $ts,
                claimedAt: $task->claimedAt,
                completedAt: $now,
                createdAt: $task->createdAt,
                updatedAt: $now,
                parentId: $task->parentId,
                workInstructions: $task->workInstructions,
                acceptanceCriteria: $task->acceptanceCriteria,
                reviewStatus: 'accepted',
                reviewFeedback: $reviewResult->feedback,
                archived: $task->archived,
                gitBranch: $task->gitBranch,
                mergeAttempts: $task->mergeAttempts,
                testCommand: $task->testCommand,
                dependsOn: $task->dependsOn,
                autoMerge: $task->autoMerge,
                prUrl: $task->prUrl,
            );
            // Atomic CAS: only complete if still in PendingReview
            $transitioned = $this->db->transitionTask($updated, [TaskStatus::PendingReview]);
            if (!$transitioned) {
                return;
            }
            $this->gossip->gossipTaskComplete($taskId, $task->assignedTo ?? '', $task->result, $ts);

            // Trigger dispatch to unblock dependent tasks
            $this->dispatcher?->triggerDispatch();

            // Check if all subtasks are done — complete the parent
            if ($task->parentId) {
                $this->tryCompleteParent($task->parentId);
            }
        } else {
            // Count previous rejections
            $rejectionCount = substr_count($task->reviewFeedback, '[Rejection');
            if ($rejectionCount >= self::MAX_REJECTIONS) {
                // Too many rejections — mark as failed via atomic fail path
                $this->db->updateReviewStatus($taskId, 'rejected', $reviewResult->feedback);
                $this->failFromStatus($taskId, $task->assignedTo ?? '', "Max rejections reached: {$reviewResult->feedback}", TaskStatus::PendingReview);
                return;
            }

            // Append feedback to work instructions for the next attempt
            $feedbackHistory = $task->reviewFeedback
                ? $task->reviewFeedback . "\n"
                : '';
            $feedbackHistory .= "[Rejection " . ($rejectionCount + 1) . "] " . $reviewResult->feedback;

            $this->db->updateReviewStatus($taskId, 'rejected', $feedbackHistory);

            // Requeue with feedback appended to work instructions
            $newInstructions = $task->workInstructions;
            if ($newInstructions) {
                $newInstructions .= "\n\n";
            }
            $newInstructions .= "## Previous Attempt Feedback\n" . $reviewResult->feedback;

            $now = gmdate('Y-m-d\TH:i:s\Z');
            $updated = new TaskModel(
                id: $task->id,
                title: $task->title,
                description: $task->description,
                status: TaskStatus::Pending,
                priority: $task->priority,
                requiredCapabilities: $task->requiredCapabilities,
                createdBy: $task->createdBy,
                assignedTo: null,
                assignedNode: null,
                result: null,
                error: null,
                progress: null,
                projectPath: $task->projectPath,
                context: $task->context,
                lamportTs: $ts,
                claimedAt: null,
                completedAt: null,
                createdAt: $task->createdAt,
                updatedAt: $now,
                parentId: $task->parentId,
                workInstructions: $newInstructions,
                acceptanceCriteria: $task->acceptanceCriteria,
                reviewStatus: 'rejected',
                reviewFeedback: $feedbackHistory,
                archived: $task->archived,
                gitBranch: $task->gitBranch,
                mergeAttempts: $task->mergeAttempts,
                testCommand: $task->testCommand,
                dependsOn: $task->dependsOn,
                autoMerge: $task->autoMerge,
                prUrl: $task->prUrl,
            );
            // Atomic CAS: only requeue if still in PendingReview
            $transitioned = $this->db->transitionTask($updated, [TaskStatus::PendingReview]);
            if (!$transitioned) {
                return;
            }
            $this->gossip->gossipTaskUpdate($taskId, '', TaskStatus::Pending->value, null, $ts);
        }
    }

    /**
     * @return bool True if the failure was processed, false if rejected (terminal/not found).
     */
    public function fail(string $taskId, string $agentId, ?string $error = null): bool
    {
        $ts = $this->clock->tick();

        $task = $this->db->getTask($taskId);
        if (!$task) {
            $this->log("fail() rejected: task {$taskId} not found");
            return false;
        }
        if ($task->status->isTerminal()) {
            $this->log("fail() rejected: task {$taskId} already in terminal state '{$task->status->value}'");
            return false;
        }
        if (!$task->status->isWorkableByAgent()) {
            $this->log("fail() rejected: task {$taskId} not workable from state '{$task->status->value}'");
            return false;
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $updated = new TaskModel(
            id: $task->id,
            title: $task->title,
            description: $task->description,
            status: TaskStatus::Failed,
            priority: $task->priority,
            requiredCapabilities: $task->requiredCapabilities,
            createdBy: $task->createdBy,
            assignedTo: $agentId,
            assignedNode: $task->assignedNode,
            result: null,
            error: $error,
            progress: null,
            projectPath: $task->projectPath,
            context: $task->context,
            lamportTs: $ts,
            claimedAt: $task->claimedAt,
            completedAt: $now,
            createdAt: $task->createdAt,
            updatedAt: $now,
            parentId: $task->parentId,
            workInstructions: $task->workInstructions,
            acceptanceCriteria: $task->acceptanceCriteria,
            reviewStatus: $task->reviewStatus,
            reviewFeedback: $task->reviewFeedback,
            archived: $task->archived,
            gitBranch: $task->gitBranch,
            mergeAttempts: $task->mergeAttempts,
            testCommand: $task->testCommand,
            dependsOn: $task->dependsOn,
            autoMerge: $task->autoMerge,
            prUrl: $task->prUrl,
        );
        // Atomic CAS: only fail if still in agent-workable state
        $transitioned = $this->db->transitionTask($updated, [
            TaskStatus::Claimed,
            TaskStatus::InProgress,
            TaskStatus::WaitingInput,
        ]);
        if (!$transitioned) {
            return false;
        }
        $this->gossip->gossipTaskFail($taskId, $agentId, $error, $ts);

        // Trigger dispatch to cascade-fail blocked dependents
        $this->dispatcher?->triggerDispatch();

        // Check if all subtasks are done — complete the parent
        if ($task->parentId) {
            $this->tryCompleteParent($task->parentId);
        }

        return true;
    }

    /**
     * Fail a task from a specific known status (e.g., PendingReview after max rejections).
     */
    private function failFromStatus(string $taskId, string $agentId, string $error, TaskStatus $fromStatus): void
    {
        $ts = $this->clock->tick();

        $task = $this->db->getTask($taskId);
        if (!$task) {
            return;
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $updated = new TaskModel(
            id: $task->id,
            title: $task->title,
            description: $task->description,
            status: TaskStatus::Failed,
            priority: $task->priority,
            requiredCapabilities: $task->requiredCapabilities,
            createdBy: $task->createdBy,
            assignedTo: $agentId,
            assignedNode: $task->assignedNode,
            result: null,
            error: $error,
            progress: null,
            projectPath: $task->projectPath,
            context: $task->context,
            lamportTs: $ts,
            claimedAt: $task->claimedAt,
            completedAt: $now,
            createdAt: $task->createdAt,
            updatedAt: $now,
            parentId: $task->parentId,
            workInstructions: $task->workInstructions,
            acceptanceCriteria: $task->acceptanceCriteria,
            reviewStatus: $task->reviewStatus,
            reviewFeedback: $task->reviewFeedback,
            archived: $task->archived,
            gitBranch: $task->gitBranch,
            mergeAttempts: $task->mergeAttempts,
            testCommand: $task->testCommand,
            dependsOn: $task->dependsOn,
            autoMerge: $task->autoMerge,
            prUrl: $task->prUrl,
        );
        $transitioned = $this->db->transitionTask($updated, [$fromStatus]);
        if (!$transitioned) {
            return;
        }
        $this->gossip->gossipTaskFail($taskId, $agentId, $error, $ts);

        // Trigger dispatch to cascade-fail blocked dependents
        $this->dispatcher?->triggerDispatch();
    }

    /**
     * Complete the parent task if all its subtasks are done.
     * If git branches exist, runs merge-test-retry loop first.
     * Public so Server can call it as a safety net on gossip-received completions.
     */
    public function tryCompleteParent(string $parentId): void
    {
        // Use a transaction to atomically check subtasks and transition the parent.
        // This prevents races where multiple subtask completions trigger concurrent
        // parent transitions.
        $this->db->beginTransaction();
        try {
            $subtasks = $this->db->getSubtasks($parentId);
            if (empty($subtasks)) {
                $this->db->commit();
                return;
            }

            foreach ($subtasks as $sub) {
                if (!$sub->status->isTerminal()) {
                    $this->db->commit();
                    return; // Still has active subtasks
                }
            }

            // All subtasks are terminal
            $parent = $this->db->getTask($parentId);
            if (!$parent || $parent->status->isTerminal()) {
                $this->db->commit();
                return;
            }

            $completedSubs = [];
            $failedCount = 0;
            foreach ($subtasks as $sub) {
                if ($sub->status === TaskStatus::Completed) {
                    $completedSubs[] = $sub;
                } else {
                    $failedCount++;
                }
            }

            // If ALL subtasks failed, fail the parent immediately
            if (empty($completedSubs)) {
                $ts = $this->clock->tick();
                $now = gmdate('Y-m-d\TH:i:s\Z');
                $updated = $parent->withStatus(TaskStatus::Failed, $ts);
                $failedParent = new TaskModel(
                    id: $updated->id, title: $updated->title, description: $updated->description,
                    status: $updated->status, priority: $updated->priority,
                    requiredCapabilities: $updated->requiredCapabilities, createdBy: $updated->createdBy,
                    assignedTo: $updated->assignedTo, assignedNode: $updated->assignedNode,
                    result: null, error: "All {$failedCount} subtask(s) failed", progress: null,
                    projectPath: $updated->projectPath, context: $updated->context,
                    lamportTs: $updated->lamportTs, claimedAt: $updated->claimedAt,
                    completedAt: $now, createdAt: $updated->createdAt, updatedAt: $now,
                    parentId: $updated->parentId, workInstructions: $updated->workInstructions,
                    acceptanceCriteria: $updated->acceptanceCriteria, reviewStatus: $updated->reviewStatus,
                    reviewFeedback: $updated->reviewFeedback, archived: $updated->archived,
                    gitBranch: $updated->gitBranch, mergeAttempts: $updated->mergeAttempts,
                    testCommand: $updated->testCommand, dependsOn: $updated->dependsOn,
                    autoMerge: $updated->autoMerge, prUrl: $updated->prUrl,
                );
                // Atomic CAS within transaction: only fail if parent is in expected non-terminal state
                $transitioned = $this->db->transitionTask($failedParent, [
                    TaskStatus::Pending, TaskStatus::Planning, TaskStatus::Claimed,
                    TaskStatus::InProgress, TaskStatus::PendingReview, TaskStatus::WaitingInput,
                    TaskStatus::Merging,
                ]);
                $this->db->commit();
                if ($transitioned) {
                    $this->gossip->gossipTaskFail($parentId, '', "All {$failedCount} subtask(s) failed", $failedParent->lamportTs);
                }
                return;
            }

            // Collect git branches from completed subtasks
            $branches = [];
            foreach ($completedSubs as $sub) {
                if ($sub->gitBranch !== '') {
                    $branches[] = $sub->gitBranch;
                }
            }

            $baseDir = getcwd() . '/workbench/.base';

            // If no git branches or no git workspace configured, complete immediately (backward compatible)
            if (empty($branches) || $this->git === null || !is_dir($baseDir . '/.git')) {
                $this->db->commit();
                $this->completeParentDirectly($parent, count($completedSubs), $failedCount);
                return;
            }

            // Atomically set parent status to Merging
            $ts = $this->clock->tick();
            $mergingParent = $parent->withStatus(TaskStatus::Merging, $ts);
            $transitioned = $this->db->transitionTask($mergingParent, [
                TaskStatus::Pending, TaskStatus::Planning, TaskStatus::Claimed,
                TaskStatus::InProgress, TaskStatus::PendingReview, TaskStatus::WaitingInput,
            ]);
            $this->db->commit();

            if (!$transitioned) {
                return; // Parent state changed underneath us
            }
            $this->gossip->gossipTaskUpdate($parentId, '', TaskStatus::Merging->value, 'Merging subtask branches...', $ts);

            // Spawn merge-test coroutine
            Coroutine::create(function () use ($parentId, $branches, $completedSubs, $baseDir) {
                $this->mergeAndTest($parentId, $branches, $completedSubs, $baseDir);
            });
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Merge-test-retry loop: merge subtask branches, run tests, retry on failure.
     */
    private function mergeAndTest(string $parentId, array $branches, array $completedSubs, string $baseDir): void
    {
        $parent = $this->db->getTask($parentId);
        if (!$parent || !$this->git) {
            return;
        }

        // Increment merge attempts
        $attempts = $this->db->incrementMergeAttempts($parentId);

        if ($attempts > self::MAX_REJECTIONS) {
            $this->failParent($parentId, "Max merge attempts ({$attempts}) exceeded");
            return;
        }

        $mergeWorkDir = $this->mergeWorkDir ?: (getcwd() . '/workbench/.merge');
        $integrationBranch = 'integrate/' . substr($parentId, 0, 8);

        $this->log("Merge attempt {$attempts}/{self::MAX_REJECTIONS} for parent {$parentId}");

        // 1. Create/reset merge worktree
        $created = $this->git->createMergeWorktree($baseDir, $mergeWorkDir, $integrationBranch);
        if (!$created) {
            $this->failParent($parentId, 'Failed to create merge worktree');
            return;
        }

        // 2. Merge all subtask branches
        $mergeResult = $this->git->mergeSubtaskBranches($mergeWorkDir, $branches, $baseDir);

        if (!$mergeResult->success) {
            $this->handleMergeFailure($parent, $completedSubs, $mergeResult);
            return;
        }

        // 3. Run tests
        $testCommand = $parent->testCommand ?: $this->globalTestCommand;
        $testResult = $this->git->runTests($mergeWorkDir, $testCommand);

        if (!$testResult->success) {
            $this->handleTestFailure($parent, $completedSubs, $testResult);
            return;
        }

        // 4. All passed — push integration branch and create PR
        $this->log("Merge+test passed for parent {$parentId}");

        $pushed = $this->git->commitAndPush($mergeWorkDir, "Integration: {$parent->title}", $integrationBranch);
        // Even if push only has existing commits, try creating PR
        if (!$pushed) {
            // Push the branch even if no new commits (merge commits already exist)
            $output = [];
            exec(sprintf(
                'cd %s && git push -u origin %s 2>&1',
                escapeshellarg($mergeWorkDir),
                escapeshellarg($integrationBranch),
            ), $output, $code);
        }

        $prUrl = $this->git->createPullRequest(
            $mergeWorkDir,
            $parent->title,
            "## Integration PR\n\n{$parent->description}\n\n### Merged branches\n" .
            implode("\n", array_map(fn($b) => "- `{$b}`", $branches)),
        );

        $result = "All " . count($completedSubs) . " subtask(s) merged and tests passed";
        if ($prUrl) {
            $result .= "\n\nPR: {$prUrl}";
        }

        // Complete parent atomically — only if still in Merging state
        $ts = $this->clock->tick();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $updated = new TaskModel(
            id: $parent->id, title: $parent->title, description: $parent->description,
            status: TaskStatus::Completed, priority: $parent->priority,
            requiredCapabilities: $parent->requiredCapabilities, createdBy: $parent->createdBy,
            assignedTo: $parent->assignedTo, assignedNode: $parent->assignedNode,
            result: $result, error: null, progress: null,
            projectPath: $parent->projectPath, context: $parent->context,
            lamportTs: $ts, claimedAt: $parent->claimedAt, completedAt: $now,
            createdAt: $parent->createdAt, updatedAt: $now,
            parentId: $parent->parentId, workInstructions: $parent->workInstructions,
            acceptanceCriteria: $parent->acceptanceCriteria, reviewStatus: $parent->reviewStatus,
            reviewFeedback: $parent->reviewFeedback, archived: $parent->archived,
            gitBranch: $integrationBranch, mergeAttempts: $parent->mergeAttempts,
            testCommand: $parent->testCommand, dependsOn: $parent->dependsOn,
            autoMerge: $parent->autoMerge, prUrl: $prUrl ?: $parent->prUrl,
        );
        $transitioned = $this->db->transitionTask($updated, [TaskStatus::Merging]);
        if (!$transitioned) {
            return;
        }
        $this->gossip->gossipTaskComplete($parentId, '', $result, $ts);
    }

    /**
     * Handle merge failure: requeue only the conflicting subtasks.
     */
    private function handleMergeFailure(TaskModel $parent, array $completedSubs, \VoidLux\Swarm\Git\MergeResult $mergeResult): void
    {
        $this->log("Merge conflict for parent {$parent->id}: " . implode(', ', $mergeResult->conflictingBranches));

        $conflictOutput = substr($mergeResult->conflictOutput, 0, 2000);
        $ts = $this->clock->tick();

        // Requeue only subtasks whose branches conflicted
        foreach ($completedSubs as $sub) {
            if ($sub->gitBranch !== '' && in_array($sub->gitBranch, $mergeResult->conflictingBranches, true)) {
                $newInstructions = $sub->workInstructions;
                if ($newInstructions) {
                    $newInstructions .= "\n\n";
                }
                $newInstructions .= "## Merge Conflict (attempt {$parent->mergeAttempts})\n"
                    . "Your branch `{$sub->gitBranch}` conflicted during integration merge.\n"
                    . "Please resolve conflicts with other subtask branches and retry.\n\n"
                    . "Conflict output:\n```\n{$conflictOutput}\n```";

                $this->requeueCompletedSubtask($sub, $newInstructions, $ts);
            }
        }

        // Atomically reset parent from Merging to InProgress
        $updated = $parent->withStatus(TaskStatus::InProgress, $ts);
        $transitioned = $this->db->transitionTask($updated, [TaskStatus::Merging]);
        if ($transitioned) {
            $this->gossip->gossipTaskUpdate($parent->id, '', TaskStatus::InProgress->value, 'Merge conflict — requeued conflicting subtasks', $ts);
            $this->dispatcher?->triggerDispatch();
        }
    }

    /**
     * Handle test failure: requeue ALL completed subtasks with test output.
     */
    private function handleTestFailure(TaskModel $parent, array $completedSubs, \VoidLux\Swarm\Git\TestResult $testResult): void
    {
        $this->log("Tests failed for parent {$parent->id} (exit code {$testResult->exitCode})");

        $testOutput = substr($testResult->output, 0, 2000);
        $ts = $this->clock->tick();

        // Requeue ALL completed subtasks with test failure context
        foreach ($completedSubs as $sub) {
            $newInstructions = $sub->workInstructions;
            if ($newInstructions) {
                $newInstructions .= "\n\n";
            }
            $newInstructions .= "## Test Failure (attempt {$parent->mergeAttempts})\n"
                . "Integration tests failed after merging all subtask branches.\n"
                . "Please review and fix your changes to ensure tests pass.\n\n"
                . "Test output:\n```\n{$testOutput}\n```";

            $this->requeueCompletedSubtask($sub, $newInstructions, $ts);
        }

        // Atomically reset parent from Merging to InProgress
        $updated = $parent->withStatus(TaskStatus::InProgress, $ts);
        $transitioned = $this->db->transitionTask($updated, [TaskStatus::Merging]);
        if ($transitioned) {
            $this->gossip->gossipTaskUpdate($parent->id, '', TaskStatus::InProgress->value, 'Tests failed — requeued all subtasks', $ts);
            $this->dispatcher?->triggerDispatch();
        }
    }

    /**
     * Requeue a completed subtask back to pending with updated instructions.
     */
    private function requeueCompletedSubtask(TaskModel $sub, string $newInstructions, int $ts): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $updated = new TaskModel(
            id: $sub->id, title: $sub->title, description: $sub->description,
            status: TaskStatus::Pending, priority: $sub->priority,
            requiredCapabilities: $sub->requiredCapabilities, createdBy: $sub->createdBy,
            assignedTo: null, assignedNode: null,
            result: null, error: null, progress: null,
            projectPath: $sub->projectPath, context: $sub->context,
            lamportTs: $ts, claimedAt: null, completedAt: null,
            createdAt: $sub->createdAt, updatedAt: $now,
            parentId: $sub->parentId, workInstructions: $newInstructions,
            acceptanceCriteria: $sub->acceptanceCriteria, reviewStatus: $sub->reviewStatus,
            reviewFeedback: $sub->reviewFeedback, archived: $sub->archived,
            gitBranch: $sub->gitBranch, mergeAttempts: $sub->mergeAttempts,
            testCommand: $sub->testCommand, dependsOn: $sub->dependsOn,
            autoMerge: $sub->autoMerge, prUrl: $sub->prUrl,
        );
        // Atomic CAS: only requeue if subtask is still in Completed state.
        // Prevents races where a subtask is being re-claimed while we try to requeue it.
        $transitioned = $this->db->transitionTask($updated, [TaskStatus::Completed]);
        if (!$transitioned) {
            return;
        }
        $this->gossip->gossipTaskUpdate($sub->id, '', TaskStatus::Pending->value, null, $ts);
    }

    /**
     * Fail a parent task.
     */
    private function failParent(string $parentId, string $error): void
    {
        $ts = $this->clock->tick();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $parent = $this->db->getTask($parentId);
        if (!$parent || $parent->status->isTerminal()) {
            return;
        }

        $updated = new TaskModel(
            id: $parent->id, title: $parent->title, description: $parent->description,
            status: TaskStatus::Failed, priority: $parent->priority,
            requiredCapabilities: $parent->requiredCapabilities, createdBy: $parent->createdBy,
            assignedTo: $parent->assignedTo, assignedNode: $parent->assignedNode,
            result: null, error: $error, progress: null,
            projectPath: $parent->projectPath, context: $parent->context,
            lamportTs: $ts, claimedAt: $parent->claimedAt, completedAt: $now,
            createdAt: $parent->createdAt, updatedAt: $now,
            parentId: $parent->parentId, workInstructions: $parent->workInstructions,
            acceptanceCriteria: $parent->acceptanceCriteria, reviewStatus: $parent->reviewStatus,
            reviewFeedback: $parent->reviewFeedback, archived: $parent->archived,
            gitBranch: $parent->gitBranch, mergeAttempts: $parent->mergeAttempts,
            testCommand: $parent->testCommand, dependsOn: $parent->dependsOn,
            autoMerge: $parent->autoMerge, prUrl: $parent->prUrl,
        );
        // Atomic CAS: only fail if parent hasn't already transitioned to terminal
        $transitioned = $this->db->transitionTask($updated, [
            TaskStatus::Pending, TaskStatus::Planning, TaskStatus::Claimed,
            TaskStatus::InProgress, TaskStatus::PendingReview, TaskStatus::WaitingInput,
            TaskStatus::Merging,
        ]);
        if (!$transitioned) {
            return;
        }
        $this->gossip->gossipTaskFail($parentId, '', $error, $ts);
    }

    /**
     * Complete parent directly without merge (backward compatible for non-git projects).
     */
    private function completeParentDirectly(TaskModel $parent, int $completedCount, int $failedCount): void
    {
        $ts = $this->clock->tick();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $result = "All {$completedCount} subtask(s) completed" . ($failedCount ? ", {$failedCount} failed" : '');

        $updated = new TaskModel(
            id: $parent->id, title: $parent->title, description: $parent->description,
            status: TaskStatus::Completed, priority: $parent->priority,
            requiredCapabilities: $parent->requiredCapabilities, createdBy: $parent->createdBy,
            assignedTo: $parent->assignedTo, assignedNode: $parent->assignedNode,
            result: $result, error: null, progress: null,
            projectPath: $parent->projectPath, context: $parent->context,
            lamportTs: $ts, claimedAt: $parent->claimedAt, completedAt: $now,
            createdAt: $parent->createdAt, updatedAt: $now,
            parentId: $parent->parentId, workInstructions: $parent->workInstructions,
            acceptanceCriteria: $parent->acceptanceCriteria, reviewStatus: $parent->reviewStatus,
            reviewFeedback: $parent->reviewFeedback, archived: $parent->archived,
            gitBranch: $parent->gitBranch, mergeAttempts: $parent->mergeAttempts,
            testCommand: $parent->testCommand, dependsOn: $parent->dependsOn,
            autoMerge: $parent->autoMerge, prUrl: $parent->prUrl,
        );
        // Atomic CAS: only complete parent if not already transitioned
        $transitioned = $this->db->transitionTask($updated, [
            TaskStatus::Pending, TaskStatus::Planning, TaskStatus::Claimed,
            TaskStatus::InProgress, TaskStatus::PendingReview, TaskStatus::WaitingInput,
            TaskStatus::Merging,
        ]);
        if (!$transitioned) {
            return;
        }
        $this->gossip->gossipTaskComplete($parent->id, '', $result, $ts);
    }

    /**
     * Requeue a task back to pending (e.g. agent died mid-task).
     * Only requeues if the task is in claimed or in_progress state.
     */
    public function requeue(string $taskId, string $reason): bool
    {
        $ts = $this->clock->tick();
        $requeued = $this->db->requeueTask($taskId, $ts, $reason);

        if ($requeued) {
            $this->gossip->gossipTaskUpdate($taskId, '', TaskStatus::Pending->value, null, $ts);
        }

        return $requeued;
    }

    public function cancel(string $taskId): bool
    {
        $task = $this->db->getTask($taskId);
        if (!$task || $task->status->isTerminal()) {
            return false;
        }

        $ts = $this->clock->tick();
        $updated = $task->withStatus(TaskStatus::Cancelled, $ts);
        $this->db->updateTask($updated);
        $this->gossip->gossipTaskCancel($taskId, $ts);
        return true;
    }

    public function getTask(string $id): ?TaskModel
    {
        return $this->db->getTask($id);
    }

    /** @return TaskModel[] */
    public function getTasks(?string $status = null): array
    {
        return $this->db->getTasksByStatus($status);
    }

    public function getNextPendingTask(array $capabilities): ?TaskModel
    {
        return $this->db->getNextPendingTask($capabilities);
    }

    /**
     * Archive a single terminal task. Returns the updated task or null.
     */
    public function archiveTask(string $taskId): ?TaskModel
    {
        $task = $this->db->getTask($taskId);
        if (!$task || !$task->status->isTerminal()) {
            return null;
        }

        $ts = $this->clock->tick();
        $this->db->archiveTask($taskId);
        $this->gossip->gossipTaskArchive($taskId, $ts);

        return $this->db->getTask($taskId);
    }

    /**
     * Archive all terminal tasks. Returns IDs of archived tasks.
     * @return string[]
     */
    public function archiveAllTerminal(): array
    {
        // Get terminal tasks that aren't archived yet
        $tasks = $this->db->getTasksByStatus();
        $terminalIds = [];
        foreach ($tasks as $task) {
            if ($task->status->isTerminal() && !$task->archived) {
                $terminalIds[] = $task->id;
            }
        }

        if (empty($terminalIds)) {
            return [];
        }

        $this->db->archiveAllTerminal();
        $ts = $this->clock->tick();

        foreach ($terminalIds as $id) {
            $this->gossip->gossipTaskArchive($id, $ts);
        }

        return $terminalIds;
    }

    private function log(string $message): void
    {
        $time = date('H:i:s');
        echo "[{$time}][merge] {$message}\n";
    }
}
