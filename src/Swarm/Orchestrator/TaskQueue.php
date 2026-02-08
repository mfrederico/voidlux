<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Orchestrator;

use Swoole\Coroutine;
use VoidLux\P2P\Protocol\LamportClock;
use VoidLux\Swarm\Ai\TaskReviewer;
use VoidLux\Swarm\Gossip\TaskGossipEngine;
use VoidLux\Swarm\Model\TaskModel;
use VoidLux\Swarm\Model\TaskStatus;
use VoidLux\Swarm\Storage\SwarmDatabase;

/**
 * Task lifecycle management: create, claim, update, complete, fail, cancel.
 */
class TaskQueue
{
    private ?TaskReviewer $reviewer = null;
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
    ): TaskModel {
        $ts = $this->clock->tick();
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
        );

        return $this->gossip->createTask($task);
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
        if (!$task || $task->status->isTerminal()) {
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
        );
        $this->db->updateTask($updated);
        $this->gossip->gossipTaskUpdate($taskId, $agentId, TaskStatus::InProgress->value, $progress, $ts);
    }

    public function setWaitingInput(string $taskId, string $agentId, string $question): void
    {
        $ts = $this->clock->tick();

        $task = $this->db->getTask($taskId);
        if (!$task || $task->status->isTerminal()) {
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
        );
        $this->db->updateTask($updated);
        $this->gossip->gossipTaskUpdate($taskId, $agentId, TaskStatus::WaitingInput->value, $question, $ts);
    }

    public function complete(string $taskId, string $agentId, ?string $result = null): void
    {
        $ts = $this->clock->tick();

        $task = $this->db->getTask($taskId);
        if (!$task || $task->status->isTerminal()) {
            return;
        }

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
            );
            $this->db->updateTask($updated);
            $this->gossip->gossipTaskUpdate($taskId, $agentId, TaskStatus::PendingReview->value, null, $ts);

            // Spawn review coroutine
            Coroutine::create(function () use ($taskId) {
                $this->reviewTask($taskId);
            });
            return;
        }

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
            completedAt: gmdate('Y-m-d\TH:i:s\Z'),
            createdAt: $task->createdAt,
            updatedAt: gmdate('Y-m-d\TH:i:s\Z'),
            parentId: $task->parentId,
            workInstructions: $task->workInstructions,
            acceptanceCriteria: $task->acceptanceCriteria,
            reviewStatus: $task->reviewStatus,
            reviewFeedback: $task->reviewFeedback,
        );
        $this->db->updateTask($updated);
        $this->gossip->gossipTaskComplete($taskId, $agentId, $result, $ts);
    }

    /**
     * Review a task using the AI reviewer.
     * Accepts or rejects with feedback. Rejected tasks get requeued (up to MAX_REJECTIONS).
     */
    public function reviewTask(string $taskId): void
    {
        $task = $this->db->getTask($taskId);
        if (!$task || !$this->reviewer) {
            return;
        }

        $reviewResult = $this->reviewer->review($task, $task->result ?? '');
        $ts = $this->clock->tick();

        if ($reviewResult->accepted) {
            $this->db->updateReviewStatus($taskId, 'accepted', $reviewResult->feedback);

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
                completedAt: gmdate('Y-m-d\TH:i:s\Z'),
                createdAt: $task->createdAt,
                updatedAt: gmdate('Y-m-d\TH:i:s\Z'),
                parentId: $task->parentId,
                workInstructions: $task->workInstructions,
                acceptanceCriteria: $task->acceptanceCriteria,
                reviewStatus: 'accepted',
                reviewFeedback: $reviewResult->feedback,
            );
            $this->db->updateTask($updated);
            $this->gossip->gossipTaskComplete($taskId, $task->assignedTo ?? '', $task->result, $ts);
        } else {
            // Count previous rejections
            $rejectionCount = substr_count($task->reviewFeedback, '[Rejection');
            if ($rejectionCount >= self::MAX_REJECTIONS) {
                // Too many rejections — mark as failed
                $this->db->updateReviewStatus($taskId, 'rejected', $reviewResult->feedback);
                $this->fail($taskId, $task->assignedTo ?? '', "Max rejections reached: {$reviewResult->feedback}");
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
            );
            $this->db->updateTask($updated);
            $this->gossip->gossipTaskUpdate($taskId, '', TaskStatus::Pending->value, null, $ts);
        }
    }

    public function fail(string $taskId, string $agentId, ?string $error = null): void
    {
        $ts = $this->clock->tick();

        $task = $this->db->getTask($taskId);
        if (!$task || $task->status->isTerminal()) {
            return;
        }

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
            completedAt: gmdate('Y-m-d\TH:i:s\Z'),
            createdAt: $task->createdAt,
            updatedAt: gmdate('Y-m-d\TH:i:s\Z'),
            parentId: $task->parentId,
            workInstructions: $task->workInstructions,
            acceptanceCriteria: $task->acceptanceCriteria,
            reviewStatus: $task->reviewStatus,
            reviewFeedback: $task->reviewFeedback,
        );
        $this->db->updateTask($updated);
        $this->gossip->gossipTaskFail($taskId, $agentId, $error, $ts);
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
}
