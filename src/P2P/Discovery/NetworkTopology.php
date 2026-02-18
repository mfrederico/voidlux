<?php

declare(strict_types=1);

namespace VoidLux\P2P\Discovery;

/**
 * Network topology awareness tracker.
 *
 * Maintains a view of the network structure:
 * - Which peers are reachable and their roles (emperor, worker, seneschal)
 * - Latency estimates from PING/PONG round-trips
 * - Network partitioning detection
 * - Emperor location tracking
 *
 * This is read-only state assembled from discovery and P2P events —
 * it doesn't initiate connections itself.
 */
class NetworkTopology
{
    /** @var array<string, array{host: string, p2p_port: int, http_port: int, role: string, latency_ms: float, last_seen: float, connected: bool}> */
    private array $peers = [];

    private ?string $emperorNodeId = null;
    private float $lastTopologyChange = 0;

    public function __construct(
        private readonly string $localNodeId,
    ) {
        $this->lastTopologyChange = microtime(true);
    }

    /**
     * Record a peer observation (from HELLO, PEX, DHT, or heartbeat).
     */
    public function observePeer(
        string $nodeId,
        string $host,
        int $p2pPort,
        int $httpPort = 0,
        string $role = 'worker',
        bool $connected = false,
    ): void {
        if ($nodeId === $this->localNodeId) {
            return;
        }

        $existing = $this->peers[$nodeId] ?? null;
        $changed = $existing === null
            || $existing['role'] !== $role
            || $existing['connected'] !== $connected;

        $this->peers[$nodeId] = [
            'host' => $host,
            'p2p_port' => $p2pPort,
            'http_port' => $httpPort,
            'role' => $role,
            'latency_ms' => $existing['latency_ms'] ?? 0.0,
            'last_seen' => microtime(true),
            'connected' => $connected,
        ];

        if ($role === 'emperor') {
            $this->emperorNodeId = $nodeId;
        }

        if ($changed) {
            $this->lastTopologyChange = microtime(true);
        }
    }

    /**
     * Record a latency measurement (from PONG response).
     */
    public function recordLatency(string $nodeId, float $latencyMs): void
    {
        if (isset($this->peers[$nodeId])) {
            // Exponential moving average
            $old = $this->peers[$nodeId]['latency_ms'];
            $this->peers[$nodeId]['latency_ms'] = $old > 0
                ? $old * 0.7 + $latencyMs * 0.3
                : $latencyMs;
        }
    }

    /**
     * Mark a peer as disconnected.
     */
    public function peerDisconnected(string $nodeId): void
    {
        if (isset($this->peers[$nodeId])) {
            $this->peers[$nodeId]['connected'] = false;
            $this->lastTopologyChange = microtime(true);
        }

        if ($this->emperorNodeId === $nodeId) {
            $this->emperorNodeId = null;
        }
    }

    /**
     * Get the current emperor node ID (if known).
     */
    public function getEmperorNodeId(): ?string
    {
        return $this->emperorNodeId;
    }

    /**
     * Get all currently connected peers.
     * @return array<string, array>
     */
    public function getConnectedPeers(): array
    {
        return array_filter($this->peers, fn($p) => $p['connected']);
    }

    /**
     * Get all known peers (connected or not).
     * @return array<string, array>
     */
    public function getAllKnownPeers(): array
    {
        return $this->peers;
    }

    /**
     * Get peers filtered by role.
     * @return array<string, array>
     */
    public function getPeersByRole(string $role): array
    {
        return array_filter($this->peers, fn($p) => $p['role'] === $role);
    }

    /**
     * Get the peer with the lowest latency.
     */
    public function getLowestLatencyPeer(): ?array
    {
        $best = null;
        $bestLatency = PHP_FLOAT_MAX;

        foreach ($this->peers as $nodeId => $peer) {
            if (!$peer['connected'] || $peer['latency_ms'] <= 0) {
                continue;
            }
            if ($peer['latency_ms'] < $bestLatency) {
                $bestLatency = $peer['latency_ms'];
                $best = array_merge($peer, ['node_id' => $nodeId]);
            }
        }

        return $best;
    }

    /**
     * Detect potential network partition.
     * Returns true if we can see peers but no emperor is reachable.
     */
    public function isPartitioned(): bool
    {
        $connected = $this->getConnectedPeers();
        if (empty($connected)) {
            return false; // We're alone, not partitioned
        }

        // If we know about an emperor but can't reach it
        if ($this->emperorNodeId !== null) {
            $emperor = $this->peers[$this->emperorNodeId] ?? null;
            if ($emperor && !$emperor['connected']) {
                return true;
            }
        }

        // If there are connected peers but no emperor among them
        $emperors = array_filter($connected, fn($p) => $p['role'] === 'emperor');
        return empty($emperors) && count($connected) > 0;
    }

    /**
     * Get topology statistics.
     */
    public function stats(): array
    {
        $connected = $this->getConnectedPeers();
        $roles = [];
        foreach ($this->peers as $peer) {
            $role = $peer['role'];
            $roles[$role] = ($roles[$role] ?? 0) + 1;
        }

        $latencies = array_filter(
            array_column($this->peers, 'latency_ms'),
            fn($l) => $l > 0,
        );

        return [
            'total_known' => count($this->peers),
            'total_connected' => count($connected),
            'roles' => $roles,
            'emperor' => $this->emperorNodeId ? substr($this->emperorNodeId, 0, 8) : null,
            'avg_latency_ms' => !empty($latencies) ? array_sum($latencies) / count($latencies) : 0,
            'partitioned' => $this->isPartitioned(),
            'last_change' => $this->lastTopologyChange,
        ];
    }

    /**
     * Prune peers not seen within the given threshold.
     * @return int Number pruned
     */
    public function pruneStale(float $thresholdSeconds = 600.0): int
    {
        $now = microtime(true);
        $pruned = 0;
        foreach ($this->peers as $nodeId => $peer) {
            if (!$peer['connected'] && $now - $peer['last_seen'] > $thresholdSeconds) {
                unset($this->peers[$nodeId]);
                $pruned++;
            }
        }
        if ($pruned > 0) {
            $this->lastTopologyChange = microtime(true);
        }
        return $pruned;
    }
}
