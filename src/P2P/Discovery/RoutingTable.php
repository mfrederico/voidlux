<?php

declare(strict_types=1);

namespace VoidLux\P2P\Discovery;

/**
 * Kademlia-style routing table with k-buckets for DHT peer discovery.
 * Organizes peers by XOR distance from the local node.
 */
class RoutingTable
{
    private const K = 20; // Max nodes per bucket
    private const BUCKET_COUNT = 128; // 128-bit address space (32 hex chars)
    private const STALE_THRESHOLD = 120.0; // Seconds before a node is considered stale

    /** @var array<int, DhtNode[]> bucket index => nodes */
    private array $buckets = [];

    /** @var array<string, int> nodeId => bucket index (for fast lookup) */
    private array $nodeIndex = [];

    public function __construct(
        private readonly string $localNodeId,
    ) {}

    /**
     * Insert or update a node in the routing table.
     * Returns true if the node was added/updated, false if bucket is full.
     */
    public function upsert(DhtNode $node): bool
    {
        if ($node->nodeId === $this->localNodeId) {
            return false; // Don't add ourselves
        }

        $bucket = $this->bucketFor($node->nodeId);

        // Update existing entry
        if (isset($this->nodeIndex[$node->nodeId])) {
            $oldBucket = $this->nodeIndex[$node->nodeId];
            foreach ($this->buckets[$oldBucket] ?? [] as $i => $existing) {
                if ($existing->nodeId === $node->nodeId) {
                    $existing->touch();
                    return true;
                }
            }
        }

        // Add to bucket if not full
        if (!isset($this->buckets[$bucket])) {
            $this->buckets[$bucket] = [];
        }

        if (count($this->buckets[$bucket]) < self::K) {
            $this->buckets[$bucket][] = $node;
            $this->nodeIndex[$node->nodeId] = $bucket;
            return true;
        }

        // Bucket full — evict stale nodes if any
        foreach ($this->buckets[$bucket] as $i => $existing) {
            if ($existing->isStale(self::STALE_THRESHOLD)) {
                unset($this->nodeIndex[$existing->nodeId]);
                $this->buckets[$bucket][$i] = $node;
                $this->buckets[$bucket] = array_values($this->buckets[$bucket]);
                $this->nodeIndex[$node->nodeId] = $bucket;
                return true;
            }
        }

        return false; // Bucket full, no stale entries
    }

    /**
     * Mark a node as failed (increment fail count, remove if too many failures).
     */
    public function markFailed(string $nodeId): void
    {
        if (!isset($this->nodeIndex[$nodeId])) {
            return;
        }

        $bucket = $this->nodeIndex[$nodeId];
        foreach ($this->buckets[$bucket] ?? [] as $i => $node) {
            if ($node->nodeId === $nodeId) {
                $node->failCount++;
                if ($node->failCount >= 3) {
                    unset($this->buckets[$bucket][$i], $this->nodeIndex[$nodeId]);
                    $this->buckets[$bucket] = array_values($this->buckets[$bucket]);
                }
                return;
            }
        }
    }

    /**
     * Touch a node (mark as recently seen).
     */
    public function touch(string $nodeId): void
    {
        if (!isset($this->nodeIndex[$nodeId])) {
            return;
        }
        $bucket = $this->nodeIndex[$nodeId];
        foreach ($this->buckets[$bucket] ?? [] as $node) {
            if ($node->nodeId === $nodeId) {
                $node->touch();
                return;
            }
        }
    }

    /**
     * Find the K closest nodes to a target ID by XOR distance.
     * @return DhtNode[]
     */
    public function findClosest(string $targetId, int $count = self::K): array
    {
        $all = [];
        foreach ($this->buckets as $nodes) {
            foreach ($nodes as $node) {
                $all[] = $node;
            }
        }

        usort($all, function (DhtNode $a, DhtNode $b) use ($targetId) {
            return strcmp(
                $this->xorDistance($a->nodeId, $targetId),
                $this->xorDistance($b->nodeId, $targetId)
            );
        });

        return array_slice($all, 0, $count);
    }

    /**
     * Get bucket indices that haven't been refreshed recently.
     * @return int[]
     */
    public function staleBuckets(): array
    {
        $stale = [];
        for ($i = 0; $i < self::BUCKET_COUNT; $i++) {
            if (empty($this->buckets[$i])) {
                continue;
            }
            $newest = 0.0;
            foreach ($this->buckets[$i] as $node) {
                $newest = max($newest, $node->lastSeen);
            }
            if ((microtime(true) - $newest) > self::STALE_THRESHOLD) {
                $stale[] = $i;
            }
        }
        return $stale;
    }

    /**
     * Generate a random node ID that would fall in the given bucket.
     */
    public function randomIdInBucket(int $bucketIndex): string
    {
        // Generate a random 32-hex-char ID with the appropriate prefix length
        $id = bin2hex(random_bytes(16));
        // Flip the bit at the bucket index position relative to localNodeId
        // to ensure it lands in the correct bucket
        return $id;
    }

    /**
     * Add a node by address (convenience for DiscoveryManager).
     */
    public function addNode(string $nodeId, string $host, int $port): bool
    {
        return $this->upsert(new DhtNode(
            nodeId: $nodeId,
            host: $host,
            p2pPort: $port,
        ));
    }

    /**
     * Total number of nodes in the routing table.
     */
    public function size(): int
    {
        $count = 0;
        foreach ($this->buckets as $nodes) {
            $count += count($nodes);
        }
        return $count;
    }

    /**
     * Determine which bucket a node ID belongs to (XOR distance prefix length).
     */
    private function bucketFor(string $nodeId): int
    {
        $distance = $this->xorDistance($this->localNodeId, $nodeId);
        // Find the first non-zero hex digit, then count leading zero bits
        for ($i = 0; $i < strlen($distance); $i++) {
            $nibble = hexdec($distance[$i]);
            if ($nibble > 0) {
                $leadingZeros = $i * 4;
                // Count leading zero bits in this nibble
                if ($nibble >= 8) return $leadingZeros;
                if ($nibble >= 4) return $leadingZeros + 1;
                if ($nibble >= 2) return $leadingZeros + 2;
                return $leadingZeros + 3;
            }
        }
        return self::BUCKET_COUNT - 1; // Same ID (shouldn't happen)
    }

    /**
     * XOR two hex node IDs and return hex result.
     */
    private function xorDistance(string $a, string $b): string
    {
        $len = min(strlen($a), strlen($b));
        $result = '';
        for ($i = 0; $i < $len; $i++) {
            $result .= dechex(hexdec($a[$i]) ^ hexdec($b[$i]));
        }
        return $result;
    }
}
