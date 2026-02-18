<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Galactic;

/**
 * Represents a cross-swarm task delegation.
 *
 * When a swarm needs work done that it can't handle locally
 * (no capacity, missing capabilities), it delegates to another
 * swarm via the P2P mesh. The delegation tracks the full lifecycle:
 * requested → accepted/rejected → in_progress → completed/failed.
 */
class TaskDelegation
{
    public function __construct(
        public readonly string $id,
        public readonly string $sourceNodeId,
        public readonly string $targetNodeId,
        public readonly ?string $bountyId,
        public readonly ?string $tributeId,
        public readonly string $title,
        public readonly string $description,
        public readonly ?string $workInstructions,
        public readonly ?string $acceptanceCriteria,
        public readonly array $requiredCapabilities,
        public readonly ?string $projectPath,
        public readonly string $status,
        public readonly ?string $remoteTaskId,
        public readonly ?string $result,
        public readonly ?string $error,
        public readonly int $lamportTs,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    public static function create(
        string $sourceNodeId,
        string $targetNodeId,
        string $title,
        string $description,
        ?string $workInstructions = null,
        ?string $acceptanceCriteria = null,
        array $requiredCapabilities = [],
        ?string $projectPath = null,
        ?string $bountyId = null,
        ?string $tributeId = null,
        int $lamportTs = 0,
    ): self {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return new self(
            id: self::generateUuid(),
            sourceNodeId: $sourceNodeId,
            targetNodeId: $targetNodeId,
            bountyId: $bountyId,
            tributeId: $tributeId,
            title: $title,
            description: $description,
            workInstructions: $workInstructions,
            acceptanceCriteria: $acceptanceCriteria,
            requiredCapabilities: $requiredCapabilities,
            projectPath: $projectPath,
            status: 'requested',
            remoteTaskId: null,
            result: null,
            error: null,
            lamportTs: $lamportTs,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            sourceNodeId: $data['source_node_id'],
            targetNodeId: $data['target_node_id'],
            bountyId: $data['bounty_id'] ?? null,
            tributeId: $data['tribute_id'] ?? null,
            title: $data['title'] ?? '',
            description: $data['description'] ?? '',
            workInstructions: $data['work_instructions'] ?? null,
            acceptanceCriteria: $data['acceptance_criteria'] ?? null,
            requiredCapabilities: is_string($data['required_capabilities'] ?? '[]')
                ? json_decode($data['required_capabilities'], true) ?? []
                : ($data['required_capabilities'] ?? []),
            projectPath: $data['project_path'] ?? null,
            status: $data['status'] ?? 'requested',
            remoteTaskId: $data['remote_task_id'] ?? null,
            result: $data['result'] ?? null,
            error: $data['error'] ?? null,
            lamportTs: (int) ($data['lamport_ts'] ?? 0),
            createdAt: $data['created_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
            updatedAt: $data['updated_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source_node_id' => $this->sourceNodeId,
            'target_node_id' => $this->targetNodeId,
            'bounty_id' => $this->bountyId,
            'tribute_id' => $this->tributeId,
            'title' => $this->title,
            'description' => $this->description,
            'work_instructions' => $this->workInstructions,
            'acceptance_criteria' => $this->acceptanceCriteria,
            'required_capabilities' => $this->requiredCapabilities,
            'project_path' => $this->projectPath,
            'status' => $this->status,
            'remote_task_id' => $this->remoteTaskId,
            'result' => $this->result,
            'error' => $this->error,
            'lamport_ts' => $this->lamportTs,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    public function withStatus(string $status): self
    {
        return new self(
            id: $this->id,
            sourceNodeId: $this->sourceNodeId,
            targetNodeId: $this->targetNodeId,
            bountyId: $this->bountyId,
            tributeId: $this->tributeId,
            title: $this->title,
            description: $this->description,
            workInstructions: $this->workInstructions,
            acceptanceCriteria: $this->acceptanceCriteria,
            requiredCapabilities: $this->requiredCapabilities,
            projectPath: $this->projectPath,
            status: $status,
            remoteTaskId: $this->remoteTaskId,
            result: $this->result,
            error: $this->error,
            lamportTs: $this->lamportTs,
            createdAt: $this->createdAt,
            updatedAt: gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    public function withAccepted(string $remoteTaskId): self
    {
        return new self(
            id: $this->id,
            sourceNodeId: $this->sourceNodeId,
            targetNodeId: $this->targetNodeId,
            bountyId: $this->bountyId,
            tributeId: $this->tributeId,
            title: $this->title,
            description: $this->description,
            workInstructions: $this->workInstructions,
            acceptanceCriteria: $this->acceptanceCriteria,
            requiredCapabilities: $this->requiredCapabilities,
            projectPath: $this->projectPath,
            status: 'accepted',
            remoteTaskId: $remoteTaskId,
            result: $this->result,
            error: $this->error,
            lamportTs: $this->lamportTs,
            createdAt: $this->createdAt,
            updatedAt: gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    public function withResult(string $result): self
    {
        return new self(
            id: $this->id,
            sourceNodeId: $this->sourceNodeId,
            targetNodeId: $this->targetNodeId,
            bountyId: $this->bountyId,
            tributeId: $this->tributeId,
            title: $this->title,
            description: $this->description,
            workInstructions: $this->workInstructions,
            acceptanceCriteria: $this->acceptanceCriteria,
            requiredCapabilities: $this->requiredCapabilities,
            projectPath: $this->projectPath,
            status: 'completed',
            remoteTaskId: $this->remoteTaskId,
            result: $result,
            error: null,
            lamportTs: $this->lamportTs,
            createdAt: $this->createdAt,
            updatedAt: gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    public function withError(string $error): self
    {
        return new self(
            id: $this->id,
            sourceNodeId: $this->sourceNodeId,
            targetNodeId: $this->targetNodeId,
            bountyId: $this->bountyId,
            tributeId: $this->tributeId,
            title: $this->title,
            description: $this->description,
            workInstructions: $this->workInstructions,
            acceptanceCriteria: $this->acceptanceCriteria,
            requiredCapabilities: $this->requiredCapabilities,
            projectPath: $this->projectPath,
            status: 'failed',
            remoteTaskId: $this->remoteTaskId,
            result: null,
            error: $error,
            lamportTs: $this->lamportTs,
            createdAt: $this->createdAt,
            updatedAt: gmdate('Y-m-d\TH:i:s\Z'),
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
