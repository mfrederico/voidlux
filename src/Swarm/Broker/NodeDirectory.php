<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Broker;

/**
 * Tracks all known nodes in the P2P mesh.
 *
 * The Seneschal broker maintains this directory from HELLO, heartbeat,
 * and BROKER_NODE_ANNOUNCE messages. Used for targeted message routing
 * and capability-based node selection.
 */
class NodeDirectory
{
    /** @var array<string, NodeInfo> nodeId => info */
    private array $nodes = [];

    /** @var float Seconds before a node is considered stale */
    private float $staleThreshold = 60.0;

    public function upsert(
        string $nodeId,
        string $role,
        string $host,
        int $httpPort,
        int $p2pPort,
        array $capabilities = [],
        int $agentCount = 0,
    ): NodeInfo {
        $existing = $this->nodes[$nodeId] ?? null;

        $info = new NodeInfo(
            nodeId: $nodeId,
            role: $role,
            host: $host,
            httpPort: $httpPort,
            p2pPort: $p2pPort,
            capabilities: $capabilities,
            agentCount: $agentCount,
            lastSeen: microtime(true),
            connected: $existing?->connected ?? true,
        );

        $this->nodes[$nodeId] = $info;
        return $info;
    }

    public function markConnected(string $nodeId): void
    {
        if (isset($this->nodes[$nodeId])) {
            $this->nodes[$nodeId] = $this->nodes[$nodeId]->withConnected(true);
        }
    }

    public function markDisconnected(string $nodeId): void
    {
        if (isset($this->nodes[$nodeId])) {
            $this->nodes[$nodeId] = $this->nodes[$nodeId]->withConnected(false);
        }
    }

    public function get(string $nodeId): ?NodeInfo
    {
        return $this->nodes[$nodeId] ?? null;
    }

    public function isConnected(string $nodeId): bool
    {
        $node = $this->nodes[$nodeId] ?? null;
        return $node !== null && $node->connected;
    }

    public function getEmperor(): ?NodeInfo
    {
        foreach ($this->nodes as $node) {
            if ($node->role === 'emperor' && $node->connected) {
                return $node;
            }
        }
        return null;
    }

    /** @return NodeInfo[] */
    public function getWorkers(): array
    {
        return array_values(array_filter(
            $this->nodes,
            fn(NodeInfo $n) => $n->role === 'worker' && $n->connected,
        ));
    }

    /** @return NodeInfo[] */
    public function getAll(): array
    {
        return array_values($this->nodes);
    }

    /** @return NodeInfo[] Connected nodes only */
    public function getConnected(): array
    {
        return array_values(array_filter(
            $this->nodes,
            fn(NodeInfo $n) => $n->connected,
        ));
    }

    /**
     * Find nodes that match the given capabilities.
     * @return NodeInfo[]
     */
    public function findByCapabilities(array $required): array
    {
        if (empty($required)) {
            return $this->getConnected();
        }

        return array_values(array_filter(
            $this->nodes,
            function (NodeInfo $n) use ($required) {
                if (!$n->connected) {
                    return false;
                }
                if (empty($n->capabilities)) {
                    return true; // Empty = universal
                }
                return empty(array_diff($required, $n->capabilities));
            },
        ));
    }

    public function remove(string $nodeId): void
    {
        unset($this->nodes[$nodeId]);
    }

    public function count(): int
    {
        return count($this->nodes);
    }

    public function connectedCount(): int
    {
        return count(array_filter($this->nodes, fn(NodeInfo $n) => $n->connected));
    }

    /**
     * Prune nodes not seen within the stale threshold.
     * @return string[] Pruned node IDs
     */
    public function pruneStale(): array
    {
        $cutoff = microtime(true) - $this->staleThreshold;
        $pruned = [];

        foreach ($this->nodes as $nodeId => $node) {
            if ($node->lastSeen < $cutoff && !$node->connected) {
                $pruned[] = $nodeId;
                unset($this->nodes[$nodeId]);
            }
        }

        return $pruned;
    }

    public function toArray(): array
    {
        return array_map(fn(NodeInfo $n) => $n->toArray(), array_values($this->nodes));
    }
}
