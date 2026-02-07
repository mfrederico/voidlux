<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Orchestrator;

use VoidLux\P2P\Protocol\LamportClock;
use VoidLux\Swarm\Gossip\TaskGossipEngine;
use VoidLux\Swarm\Model\TaskModel;
use VoidLux\Swarm\Model\TaskStatus;
use VoidLux\Swarm\Storage\SwarmDatabase;

/**
 * Task lifecycle management: create, claim, update, complete, fail, cancel.
 */
class TaskQueue
{
    public function __construct(
        private readonly SwarmDatabase $db,
        private readonly TaskGossipEngine $gossip,
        private readonly LamportClock $clock,
        private readonly string $nodeId,
    ) {}

    public function createTask(
        string $title,
        string $description = '',
        int $priority = 0,
        array $requiredCapabilities = [],
        string $projectPath = '',
        string $context = '',
        string $createdBy = '',
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

    public function complete(string $taskId, string $agentId, ?string $result = null): void
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
        );
        $this->db->updateTask($updated);
        $this->gossip->gossipTaskComplete($taskId, $agentId, $result, $ts);
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
        );
        $this->db->updateTask($updated);
        $this->gossip->gossipTaskFail($taskId, $agentId, $error, $ts);
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
