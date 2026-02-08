<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Model;

class TaskModel
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $description,
        public readonly TaskStatus $status,
        public readonly int $priority,
        public readonly array $requiredCapabilities,
        public readonly string $createdBy,
        public readonly ?string $assignedTo,
        public readonly ?string $assignedNode,
        public readonly ?string $result,
        public readonly ?string $error,
        public readonly ?string $progress,
        public readonly string $projectPath,
        public readonly string $context,
        public readonly int $lamportTs,
        public readonly ?string $claimedAt,
        public readonly ?string $completedAt,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?string $parentId = null,
        public readonly string $workInstructions = '',
        public readonly string $acceptanceCriteria = '',
        public readonly string $reviewStatus = 'none',
        public readonly string $reviewFeedback = '',
        public readonly bool $archived = false,
        public readonly string $gitBranch = '',
    ) {}

    public static function create(
        string $title,
        string $description,
        string $createdBy,
        int $lamportTs,
        int $priority = 0,
        array $requiredCapabilities = [],
        string $projectPath = '',
        string $context = '',
        ?string $parentId = null,
        string $workInstructions = '',
        string $acceptanceCriteria = '',
        ?TaskStatus $status = null,
    ): self {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return new self(
            id: self::generateUuid(),
            title: $title,
            description: $description,
            status: $status ?? TaskStatus::Pending,
            priority: $priority,
            requiredCapabilities: $requiredCapabilities,
            createdBy: $createdBy,
            assignedTo: null,
            assignedNode: null,
            result: null,
            error: null,
            progress: null,
            projectPath: $projectPath,
            context: $context,
            lamportTs: $lamportTs,
            claimedAt: null,
            completedAt: null,
            createdAt: $now,
            updatedAt: $now,
            parentId: $parentId,
            workInstructions: $workInstructions,
            acceptanceCriteria: $acceptanceCriteria,
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            title: $data['title'],
            description: $data['description'] ?? '',
            status: TaskStatus::from($data['status'] ?? 'pending'),
            priority: (int) ($data['priority'] ?? 0),
            requiredCapabilities: is_string($data['required_capabilities'] ?? '[]')
                ? json_decode($data['required_capabilities'], true) ?? []
                : ($data['required_capabilities'] ?? []),
            createdBy: $data['created_by'] ?? '',
            assignedTo: $data['assigned_to'] ?? null,
            assignedNode: $data['assigned_node'] ?? null,
            result: $data['result'] ?? null,
            error: $data['error'] ?? null,
            progress: $data['progress'] ?? null,
            projectPath: $data['project_path'] ?? '',
            context: $data['context'] ?? '',
            lamportTs: (int) ($data['lamport_ts'] ?? 0),
            claimedAt: $data['claimed_at'] ?? null,
            completedAt: $data['completed_at'] ?? null,
            createdAt: $data['created_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
            updatedAt: $data['updated_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
            parentId: $data['parent_id'] ?? null,
            workInstructions: $data['work_instructions'] ?? '',
            acceptanceCriteria: $data['acceptance_criteria'] ?? '',
            reviewStatus: $data['review_status'] ?? 'none',
            reviewFeedback: $data['review_feedback'] ?? '',
            archived: !empty($data['archived']),
            gitBranch: $data['git_branch'] ?? '',
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'priority' => $this->priority,
            'required_capabilities' => $this->requiredCapabilities,
            'created_by' => $this->createdBy,
            'assigned_to' => $this->assignedTo,
            'assigned_node' => $this->assignedNode,
            'result' => $this->result,
            'error' => $this->error,
            'progress' => $this->progress,
            'project_path' => $this->projectPath,
            'context' => $this->context,
            'lamport_ts' => $this->lamportTs,
            'claimed_at' => $this->claimedAt,
            'completed_at' => $this->completedAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'parent_id' => $this->parentId,
            'work_instructions' => $this->workInstructions,
            'acceptance_criteria' => $this->acceptanceCriteria,
            'review_status' => $this->reviewStatus,
            'review_feedback' => $this->reviewFeedback,
            'archived' => $this->archived,
            'git_branch' => $this->gitBranch,
        ];
    }

    public function withStatus(TaskStatus $status, int $lamportTs): self
    {
        return new self(
            id: $this->id,
            title: $this->title,
            description: $this->description,
            status: $status,
            priority: $this->priority,
            requiredCapabilities: $this->requiredCapabilities,
            createdBy: $this->createdBy,
            assignedTo: $this->assignedTo,
            assignedNode: $this->assignedNode,
            result: $this->result,
            error: $this->error,
            progress: $this->progress,
            projectPath: $this->projectPath,
            context: $this->context,
            lamportTs: $lamportTs,
            claimedAt: $this->claimedAt,
            completedAt: $status->isTerminal() ? gmdate('Y-m-d\TH:i:s\Z') : $this->completedAt,
            createdAt: $this->createdAt,
            updatedAt: gmdate('Y-m-d\TH:i:s\Z'),
            parentId: $this->parentId,
            workInstructions: $this->workInstructions,
            acceptanceCriteria: $this->acceptanceCriteria,
            reviewStatus: $this->reviewStatus,
            reviewFeedback: $this->reviewFeedback,
            archived: $this->archived,
            gitBranch: $this->gitBranch,
        );
    }

    private static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
