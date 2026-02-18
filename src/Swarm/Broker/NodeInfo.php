<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Broker;

/**
 * Immutable snapshot of a known node's state in the broker directory.
 */
class NodeInfo
{
    public function __construct(
        public readonly string $nodeId,
        public readonly string $role,
        public readonly string $host,
        public readonly int $httpPort,
        public readonly int $p2pPort,
        public readonly array $capabilities,
        public readonly int $agentCount,
        public readonly float $lastSeen,
        public readonly bool $connected,
    ) {}

    public function withConnected(bool $connected): self
    {
        return new self(
            nodeId: $this->nodeId,
            role: $this->role,
            host: $this->host,
            httpPort: $this->httpPort,
            p2pPort: $this->p2pPort,
            capabilities: $this->capabilities,
            agentCount: $this->agentCount,
            lastSeen: microtime(true),
            connected: $connected,
        );
    }

    public function toArray(): array
    {
        return [
            'node_id' => $this->nodeId,
            'role' => $this->role,
            'host' => $this->host,
            'http_port' => $this->httpPort,
            'p2p_port' => $this->p2pPort,
            'capabilities' => $this->capabilities,
            'agent_count' => $this->agentCount,
            'last_seen' => $this->lastSeen,
            'connected' => $this->connected,
        ];
    }
}
