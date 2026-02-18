<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Upgrade;

/**
 * Records a single upgrade attempt with its outcome.
 */
class UpgradeHistory
{
    public function __construct(
        public readonly string $id,
        public readonly string $fromCommit,
        public readonly string $toCommit,
        public readonly string $status,       // pending, in_progress, success, rolled_back, failed
        public readonly string $initiatedBy,  // node_id of the Seneschal/caller
        public readonly string $failureReason,
        public readonly int $nodesTotal,
        public readonly int $nodesUpdated,
        public readonly int $nodesRolledBack,
        public readonly string $startedAt,
        public readonly string $completedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'from_commit' => $this->fromCommit,
            'to_commit' => $this->toCommit,
            'status' => $this->status,
            'initiated_by' => $this->initiatedBy,
            'failure_reason' => $this->failureReason,
            'nodes_total' => $this->nodesTotal,
            'nodes_updated' => $this->nodesUpdated,
            'nodes_rolled_back' => $this->nodesRolledBack,
            'started_at' => $this->startedAt,
            'completed_at' => $this->completedAt,
        ];
    }

    public static function fromArray(array $row): self
    {
        return new self(
            id: $row['id'] ?? '',
            fromCommit: $row['from_commit'] ?? '',
            toCommit: $row['to_commit'] ?? '',
            status: $row['status'] ?? 'pending',
            initiatedBy: $row['initiated_by'] ?? '',
            failureReason: $row['failure_reason'] ?? '',
            nodesTotal: (int) ($row['nodes_total'] ?? 0),
            nodesUpdated: (int) ($row['nodes_updated'] ?? 0),
            nodesRolledBack: (int) ($row['nodes_rolled_back'] ?? 0),
            startedAt: $row['started_at'] ?? '',
            completedAt: $row['completed_at'] ?? '',
        );
    }
}
