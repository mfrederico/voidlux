<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Galactic;

/**
 * Capability advertisement profile for a swarm node.
 *
 * Tracks what a node can do (capabilities), how well it does it
 * (acceptance rate), and how fast (avg completion time). Gossiped
 * across the P2P mesh so other swarms can discover and delegate work.
 */
class CapabilityProfile
{
    public function __construct(
        public readonly string $nodeId,
        public readonly array $capabilities,
        public readonly int $tasksCompleted,
        public readonly int $tasksFailed,
        public readonly float $acceptanceRate,
        public readonly float $avgCompletionSeconds,
        public readonly int $idleAgents,
        public readonly int $totalAgents,
        public readonly int $lamportTs,
        public readonly string $updatedAt,
    ) {}

    public static function create(
        string $nodeId,
        array $capabilities,
        int $tasksCompleted,
        int $tasksFailed,
        float $avgCompletionSeconds,
        int $idleAgents,
        int $totalAgents,
        int $lamportTs,
    ): self {
        $total = $tasksCompleted + $tasksFailed;
        $rate = $total > 0 ? round($tasksCompleted / $total, 4) : 1.0;

        return new self(
            nodeId: $nodeId,
            capabilities: $capabilities,
            tasksCompleted: $tasksCompleted,
            tasksFailed: $tasksFailed,
            acceptanceRate: $rate,
            avgCompletionSeconds: $avgCompletionSeconds,
            idleAgents: $idleAgents,
            totalAgents: $totalAgents,
            lamportTs: $lamportTs,
            updatedAt: gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            nodeId: $data['node_id'],
            capabilities: is_string($data['capabilities'] ?? '[]')
                ? json_decode($data['capabilities'], true) ?? []
                : ($data['capabilities'] ?? []),
            tasksCompleted: (int) ($data['tasks_completed'] ?? 0),
            tasksFailed: (int) ($data['tasks_failed'] ?? 0),
            acceptanceRate: (float) ($data['acceptance_rate'] ?? 1.0),
            avgCompletionSeconds: (float) ($data['avg_completion_seconds'] ?? 0),
            idleAgents: (int) ($data['idle_agents'] ?? 0),
            totalAgents: (int) ($data['total_agents'] ?? 0),
            lamportTs: (int) ($data['lamport_ts'] ?? 0),
            updatedAt: $data['updated_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    public function toArray(): array
    {
        return [
            'node_id' => $this->nodeId,
            'capabilities' => $this->capabilities,
            'tasks_completed' => $this->tasksCompleted,
            'tasks_failed' => $this->tasksFailed,
            'acceptance_rate' => $this->acceptanceRate,
            'avg_completion_seconds' => $this->avgCompletionSeconds,
            'idle_agents' => $this->idleAgents,
            'total_agents' => $this->totalAgents,
            'lamport_ts' => $this->lamportTs,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * Check if this profile can fulfill a set of required capabilities.
     * Empty capabilities = universal match (same pattern as agent dispatch).
     */
    public function matchesCapabilities(array $required): bool
    {
        if (empty($required) || empty($this->capabilities)) {
            return true;
        }
        foreach ($required as $cap) {
            if (!in_array($cap, $this->capabilities, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether this node has idle capacity to accept work.
     */
    public function hasCapacity(): bool
    {
        return $this->idleAgents > 0;
    }
}
