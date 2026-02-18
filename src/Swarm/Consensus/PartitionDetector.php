<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Consensus;

use VoidLux\P2P\PeerManager;
use VoidLux\P2P\Transport\TcpMesh;

/**
 * Detects and tracks network partition state.
 *
 * Monitors peer connectivity to determine:
 * - Whether this node is in the majority partition (has quorum)
 * - Which peers are reachable vs unreachable
 * - Partition healing (peers becoming reachable again)
 *
 * Uses a sliding window of peer liveness to avoid flapping on transient disconnects.
 */
class PartitionDetector
{
    /** Peer is considered unreachable after this many seconds without activity */
    private const PEER_TIMEOUT = 30.0;

    /** Minimum window before declaring a partition (avoid flapping) */
    private const PARTITION_GRACE_PERIOD = 15.0;

    /** @var array<string, float> node_id => last seen timestamp */
    private array $peerLastSeen = [];

    /** @var int Total known cluster size (including self) */
    private int $knownClusterSize = 1;

    /** @var float When partition was first detected (0 = no partition) */
    private float $partitionDetectedAt = 0.0;

    /** @var callable(bool $hasQuorum, int $reachable, int $total): void */
    private $onPartitionChange;

    /** @var callable(string $msg): void */
    private $logger;

    public function __construct(
        private readonly TcpMesh $mesh,
        private readonly PeerManager $peerManager,
        private readonly string $nodeId,
    ) {}

    public function onPartitionChange(callable $cb): void
    {
        $this->onPartitionChange = $cb;
    }

    public function onLog(callable $cb): void
    {
        $this->logger = $cb;
    }

    /**
     * Record that a peer was seen (via heartbeat, message, etc.).
     */
    public function peerSeen(string $nodeId): void
    {
        $this->peerLastSeen[$nodeId] = microtime(true);
    }

    /**
     * Update known cluster size. Called when peers join/leave.
     */
    public function setKnownClusterSize(int $size): void
    {
        $this->knownClusterSize = max(1, $size);
    }

    /**
     * Get count of currently reachable peers (not counting self).
     */
    public function getReachablePeerCount(): int
    {
        $now = microtime(true);
        $count = 0;
        foreach ($this->peerLastSeen as $nodeId => $lastSeen) {
            if (($now - $lastSeen) < self::PEER_TIMEOUT) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Get node IDs of currently reachable peers.
     * @return string[]
     */
    public function getReachablePeers(): array
    {
        $now = microtime(true);
        $reachable = [];
        foreach ($this->peerLastSeen as $nodeId => $lastSeen) {
            if (($now - $lastSeen) < self::PEER_TIMEOUT) {
                $reachable[] = $nodeId;
            }
        }
        return $reachable;
    }

    /**
     * Check if this node has quorum (is in the majority partition).
     * Reachable count includes self.
     */
    public function hasQuorum(): bool
    {
        $reachable = $this->getReachablePeerCount() + 1; // +1 for self
        return $reachable > ($this->knownClusterSize / 2);
    }

    /**
     * Calculate the quorum size needed for consensus.
     */
    public function quorumSize(): int
    {
        return (int) floor($this->knownClusterSize / 2) + 1;
    }

    /**
     * Evaluate partition state. Call this periodically (e.g. every heartbeat cycle).
     * Returns true if partition state changed.
     */
    public function evaluate(): bool
    {
        $now = microtime(true);
        $hadQuorum = $this->partitionDetectedAt === 0.0;
        $currentlyHasQuorum = $this->hasQuorum();

        if ($hadQuorum && !$currentlyHasQuorum) {
            // Entering partition — start grace period
            if ($this->partitionDetectedAt === 0.0) {
                $this->partitionDetectedAt = $now;
            }

            // Only fire event after grace period
            if (($now - $this->partitionDetectedAt) >= self::PARTITION_GRACE_PERIOD) {
                $this->log("Network partition detected: " . ($this->getReachablePeerCount() + 1) . "/{$this->knownClusterSize} reachable");
                $this->firePartitionChange(false);
                return true;
            }
        } elseif (!$hadQuorum && $currentlyHasQuorum) {
            // Partition healed
            $this->partitionDetectedAt = 0.0;
            $this->log("Partition healed: " . ($this->getReachablePeerCount() + 1) . "/{$this->knownClusterSize} reachable");
            $this->firePartitionChange(true);
            return true;
        } elseif ($currentlyHasQuorum) {
            $this->partitionDetectedAt = 0.0;
        }

        return false;
    }

    /**
     * Is the node currently in a partitioned state (no quorum)?
     */
    public function isPartitioned(): bool
    {
        return $this->partitionDetectedAt > 0.0
            && (microtime(true) - $this->partitionDetectedAt) >= self::PARTITION_GRACE_PERIOD;
    }

    /**
     * Prune peers that haven't been seen for a long time.
     */
    public function pruneStale(): void
    {
        $now = microtime(true);
        $cutoff = self::PEER_TIMEOUT * 3;
        foreach ($this->peerLastSeen as $nodeId => $lastSeen) {
            if (($now - $lastSeen) > $cutoff) {
                unset($this->peerLastSeen[$nodeId]);
            }
        }
    }

    /**
     * Get a diagnostic summary of the partition state.
     */
    public function getStatus(): array
    {
        return [
            'has_quorum' => $this->hasQuorum(),
            'is_partitioned' => $this->isPartitioned(),
            'reachable_peers' => $this->getReachablePeerCount(),
            'known_cluster_size' => $this->knownClusterSize,
            'quorum_size' => $this->quorumSize(),
            'reachable_node_ids' => $this->getReachablePeers(),
        ];
    }

    private function firePartitionChange(bool $hasQuorum): void
    {
        if ($this->onPartitionChange) {
            $reachable = $this->getReachablePeerCount() + 1;
            ($this->onPartitionChange)($hasQuorum, $reachable, $this->knownClusterSize);
        }
    }

    private function log(string $message): void
    {
        if ($this->logger) {
            ($this->logger)("[partition] {$message}");
        }
    }
}
