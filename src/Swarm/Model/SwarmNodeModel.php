<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Model;

/**
 * Immutable data class representing a swarm node (emperor, worker, or seneschal).
 *
 * Each node is a separate process participating in the P2P mesh. Nodes are
 * tracked by their persistent node_id and carry metadata about their role,
 * capabilities, and current workload for task assignment decisions.
 */
class SwarmNodeModel
{
    public function __construct(
        public readonly string $nodeId,
        public readonly string $role,
        public readonly string $httpHost,
        public readonly int $httpPort,
        public readonly int $p2pPort,
        public readonly array $capabilities,
        public readonly int $agentCount,
        public readonly int $activeTaskCount,
        public readonly string $status,
        public readonly ?string $lastHeartbeat,
        public readonly int $lamportTs,
        public readonly string $registeredAt,
        public readonly float $uptimeSeconds,
        public readonly int $memoryUsageBytes,
    ) {}

    public static function create(
        string $nodeId,
        string $role,
        string $httpHost,
        int $httpPort,
        int $p2pPort,
        int $lamportTs,
        array $capabilities = [],
    ): self {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return new self(
            nodeId: $nodeId,
            role: $role,
            httpHost: $httpHost,
            httpPort: $httpPort,
            p2pPort: $p2pPort,
            capabilities: $capabilities,
            agentCount: 0,
            activeTaskCount: 0,
            status: 'online',
            lastHeartbeat: $now,
            lamportTs: $lamportTs,
            registeredAt: $now,
            uptimeSeconds: 0.0,
            memoryUsageBytes: 0,
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            nodeId: $data['node_id'],
            role: $data['role'] ?? 'worker',
            httpHost: $data['http_host'] ?? '0.0.0.0',
            httpPort: (int) ($data['http_port'] ?? 0),
            p2pPort: (int) ($data['p2p_port'] ?? 0),
            capabilities: is_string($data['capabilities'] ?? '[]')
                ? json_decode($data['capabilities'], true) ?? []
                : ($data['capabilities'] ?? []),
            agentCount: (int) ($data['agent_count'] ?? 0),
            activeTaskCount: (int) ($data['active_task_count'] ?? 0),
            status: $data['status'] ?? 'offline',
            lastHeartbeat: $data['last_heartbeat'] ?? null,
            lamportTs: (int) ($data['lamport_ts'] ?? 0),
            registeredAt: $data['registered_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
            uptimeSeconds: (float) ($data['uptime_seconds'] ?? 0.0),
            memoryUsageBytes: (int) ($data['memory_usage_bytes'] ?? 0),
        );
    }

    public function toArray(): array
    {
        return [
            'node_id' => $this->nodeId,
            'role' => $this->role,
            'http_host' => $this->httpHost,
            'http_port' => $this->httpPort,
            'p2p_port' => $this->p2pPort,
            'capabilities' => $this->capabilities,
            'agent_count' => $this->agentCount,
            'active_task_count' => $this->activeTaskCount,
            'status' => $this->status,
            'last_heartbeat' => $this->lastHeartbeat,
            'lamport_ts' => $this->lamportTs,
            'registered_at' => $this->registeredAt,
            'uptime_seconds' => $this->uptimeSeconds,
            'memory_usage_bytes' => $this->memoryUsageBytes,
        ];
    }

    /**
     * Create a copy with updated workload metrics.
     */
    public function withWorkload(int $agentCount, int $activeTaskCount): self
    {
        return new self(
            nodeId: $this->nodeId,
            role: $this->role,
            httpHost: $this->httpHost,
            httpPort: $this->httpPort,
            p2pPort: $this->p2pPort,
            capabilities: $this->capabilities,
            agentCount: $agentCount,
            activeTaskCount: $activeTaskCount,
            status: $this->status,
            lastHeartbeat: $this->lastHeartbeat,
            lamportTs: $this->lamportTs,
            registeredAt: $this->registeredAt,
            uptimeSeconds: $this->uptimeSeconds,
            memoryUsageBytes: $this->memoryUsageBytes,
        );
    }

    /**
     * Create a copy with updated status and heartbeat.
     */
    public function withStatus(string $status, int $lamportTs): self
    {
        return new self(
            nodeId: $this->nodeId,
            role: $this->role,
            httpHost: $this->httpHost,
            httpPort: $this->httpPort,
            p2pPort: $this->p2pPort,
            capabilities: $this->capabilities,
            agentCount: $this->agentCount,
            activeTaskCount: $this->activeTaskCount,
            status: $status,
            lastHeartbeat: gmdate('Y-m-d\TH:i:s\Z'),
            lamportTs: $lamportTs,
            registeredAt: $this->registeredAt,
            uptimeSeconds: $this->uptimeSeconds,
            memoryUsageBytes: $this->memoryUsageBytes,
        );
    }

    /**
     * Create a copy with updated health metrics.
     */
    public function withHealth(float $uptimeSeconds, int $memoryUsageBytes): self
    {
        return new self(
            nodeId: $this->nodeId,
            role: $this->role,
            httpHost: $this->httpHost,
            httpPort: $this->httpPort,
            p2pPort: $this->p2pPort,
            capabilities: $this->capabilities,
            agentCount: $this->agentCount,
            activeTaskCount: $this->activeTaskCount,
            status: $this->status,
            lastHeartbeat: $this->lastHeartbeat,
            lamportTs: $this->lamportTs,
            registeredAt: $this->registeredAt,
            uptimeSeconds: $uptimeSeconds,
            memoryUsageBytes: $memoryUsageBytes,
        );
    }
}
