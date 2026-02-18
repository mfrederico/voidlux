<?php

declare(strict_types=1);

namespace VoidLux\P2P\Discovery;

/**
 * Kademlia-style routing table using k-buckets.
 *
 * The routing table organizes known peers by XOR distance from the local node.
 * Each bucket holds up to K nodes at a specific distance range.
 * Closer buckets are maintained more carefully (they contain fewer, more valuable peers).
 *
 * Properties:
 * - O(log n) lookups for any node in the network
 * - Self-organizing: naturally maintains good routing structure
 * - Resilient: evicts stale nodes, prefers long-lived ones
 */
class RoutingTable
{
    private const K = 8; // Nodes per bucket (Kademlia constant)
    private const BUCKET_COUNT = 128; // 128-bit node IDs (32 hex chars)
    private const STALE_THRESHOLD = 300.0; // Seconds before a node is considered stale

    /** @var array<int, DhtNode[]> k-buckets indexed by distance */
    private array $buckets = [];

    public function __construct(
        private readonly string $localNodeId,
    ) {
        for ($i = 0; $i < self::BUCKET_COUNT; $i++) {
            $this->buckets[$i] = [];
        }
    }

    /**
     * Insert or update a node in the routing table.
     * Returns true if the node was added/updated.
     */
    public function upsert(DhtNode $node): bool
    {
        if ($node->nodeId === $this->localNodeId) {
            return false;
        }

        $bucket = DhtNode::bucketIndex($this->localNodeId, $node->nodeId);
        if ($bucket < 0 || $bucket >= self::BUCKET_COUNT) {
            return false;
        }

        // Check if node already exists in bucket
        foreach ($this->buckets[$bucket] as $i => $existing) {
            if ($existing->nodeId === $node->nodeId) {
                // Move to end (most recently seen) and update
                array_splice($this->buckets[$bucket], $i, 1);
                $this->buckets[$bucket][] = $node;
                return true;
            }
        }

        // Bucket not full — add
        if (count($this->buckets[$bucket]) < self::K) {
            $this->buckets[$bucket][] = $node;
            return true;
        }

        // Bucket full — evict stale node if any, otherwise reject
        $now = microtime(true);
        foreach ($this->buckets[$bucket] as $i => $existing) {
            if ($now - $existing->lastSeen > self::STALE_THRESHOLD || $existing->failCount >= 3) {
                array_splice($this->buckets[$bucket], $i, 1);
                $this->buckets[$bucket][] = $node;
                return true;
            }
        }

        // All nodes in bucket are fresh — don't add (Kademlia prefers long-lived nodes)
        return false;
    }

    /**
     * Remove a node from the routing table.
     */
    public function remove(string $nodeId): bool
    {
        $bucket = DhtNode::bucketIndex($this->localNodeId, $nodeId);
        foreach ($this->buckets[$bucket] as $i => $node) {
            if ($node->nodeId === $nodeId) {
                array_splice($this->buckets[$bucket], $i, 1);
                return true;
            }
        }
        return false;
    }

    /**
     * Find the K closest nodes to a target ID.
     *
     * @return DhtNode[]
     */
    public function findClosest(string $targetId, int $count = self::K): array
    {
        $allNodes = $this->allNodes();

        // Sort by XOR distance to target
        usort($allNodes, function (DhtNode $a, DhtNode $b) use ($targetId) {
            $distA = DhtNode::xorDistance($a->nodeId, $targetId);
            $distB = DhtNode::xorDistance($b->nodeId, $targetId);
            return strcmp($distA, $distB);
        });

        return array_slice($allNodes, 0, $count);
    }

    /**
     * Get a node by ID.
     */
    public function get(string $nodeId): ?DhtNode
    {
        $bucket = DhtNode::bucketIndex($this->localNodeId, $nodeId);
        foreach ($this->buckets[$bucket] as $node) {
            if ($node->nodeId === $nodeId) {
                return $node;
            }
        }
        return null;
    }

    /**
     * Mark a node as failed (increment fail counter).
     */
    public function markFailed(string $nodeId): void
    {
        $bucket = DhtNode::bucketIndex($this->localNodeId, $nodeId);
        foreach ($this->buckets[$bucket] as $node) {
            if ($node->nodeId === $nodeId) {
                $node->failCount++;
                return;
            }
        }
    }

    /**
     * Touch a node (update last_seen, reset fail count).
     */
    public function touch(string $nodeId): void
    {
        $bucket = DhtNode::bucketIndex($this->localNodeId, $nodeId);
        foreach ($this->buckets[$bucket] as $node) {
            if ($node->nodeId === $nodeId) {
                $node->lastSeen = microtime(true);
                $node->failCount = 0;
                return;
            }
        }
    }

    /**
     * Get all nodes across all buckets.
     * @return DhtNode[]
     */
    public function allNodes(): array
    {
        $nodes = [];
        foreach ($this->buckets as $bucket) {
            foreach ($bucket as $node) {
                $nodes[] = $node;
            }
        }
        return $nodes;
    }

    /**
     * Get buckets that need refreshing (no activity for STALE_THRESHOLD seconds).
     * Returns bucket indices that should trigger a random lookup.
     *
     * @return int[]
     */
    public function staleBuckets(): array
    {
        $now = microtime(true);
        $stale = [];

        foreach ($this->buckets as $i => $bucket) {
            if (empty($bucket)) {
                continue;
            }
            $newest = 0.0;
            foreach ($bucket as $node) {
                $newest = max($newest, $node->lastSeen);
            }
            if ($now - $newest > self::STALE_THRESHOLD) {
                $stale[] = $i;
            }
        }

        return $stale;
    }

    /**
     * Generate a random node ID that falls into a specific bucket.
     * Used for bucket refresh lookups.
     */
    public function randomIdInBucket(int $bucketIndex): string
    {
        // Generate a random ID at the right XOR distance
        $local = hex2bin(str_pad($this->localNodeId, 32, '0', STR_PAD_LEFT));
        if ($local === false) {
            return bin2hex(random_bytes(16));
        }

        $random = random_bytes(16);

        // Set the bit at bucketIndex position, clear higher bits
        $byteIndex = 15 - intdiv($bucketIndex, 8);
        $bitIndex = $bucketIndex % 8;

        // Clear all bits above bucketIndex
        for ($i = 0; $i < $byteIndex; $i++) {
            $random[$i] = "\0";
        }
        // Set the target bit, randomize lower bits
        $mask = (1 << $bitIndex);
        $random[$byteIndex] = chr((ord($random[$byteIndex]) & ($mask - 1)) | $mask);

        // XOR with local to get the target ID
        $result = '';
        for ($i = 0; $i < 16; $i++) {
            $result .= chr(ord($local[$i]) ^ ord($random[$i]));
        }

        return bin2hex($result);
    }

    public function nodeCount(): int
    {
        $count = 0;
        foreach ($this->buckets as $bucket) {
            $count += count($bucket);
        }
        return $count;
    }

    /**
     * Get routing table statistics.
     */
    public function stats(): array
    {
        $totalNodes = 0;
        $nonEmptyBuckets = 0;
        $staleNodes = 0;
        $now = microtime(true);

        foreach ($this->buckets as $bucket) {
            if (!empty($bucket)) {
                $nonEmptyBuckets++;
            }
            $totalNodes += count($bucket);
            foreach ($bucket as $node) {
                if ($now - $node->lastSeen > self::STALE_THRESHOLD) {
                    $staleNodes++;
                }
            }
        }

        return [
            'total_nodes' => $totalNodes,
            'non_empty_buckets' => $nonEmptyBuckets,
            'stale_nodes' => $staleNodes,
            'bucket_count' => self::BUCKET_COUNT,
            'k' => self::K,
        ];
    }
}
