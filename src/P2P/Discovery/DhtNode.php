<?php

declare(strict_types=1);

namespace VoidLux\P2P\Discovery;

/**
 * A node entry in the DHT routing table.
 * Represents a known peer with its address, capabilities, and last-seen time.
 */
class DhtNode
{
    public function __construct(
        public readonly string $nodeId,
        public readonly string $host,
        public readonly int $p2pPort,
        public readonly int $httpPort,
        public readonly string $role,
        public float $lastSeen,
        public int $failCount = 0,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            nodeId: $data['node_id'] ?? '',
            host: $data['host'] ?? '',
            p2pPort: (int) ($data['p2p_port'] ?? 0),
            httpPort: (int) ($data['http_port'] ?? 0),
            role: $data['role'] ?? 'worker',
            lastSeen: (float) ($data['last_seen'] ?? microtime(true)),
            failCount: (int) ($data['fail_count'] ?? 0),
        );
    }

    public function toArray(): array
    {
        return [
            'node_id' => $this->nodeId,
            'host' => $this->host,
            'p2p_port' => $this->p2pPort,
            'http_port' => $this->httpPort,
            'role' => $this->role,
            'last_seen' => $this->lastSeen,
            'fail_count' => $this->failCount,
        ];
    }

    /**
     * XOR distance between two node IDs (Kademlia metric).
     * Returns the distance as a hex string for comparison.
     */
    public static function xorDistance(string $idA, string $idB): string
    {
        $a = hex2bin(str_pad($idA, 32, '0', STR_PAD_LEFT));
        $b = hex2bin(str_pad($idB, 32, '0', STR_PAD_LEFT));

        if ($a === false || $b === false) {
            return str_repeat('ff', 16);
        }

        $result = '';
        $len = min(strlen($a), strlen($b));
        for ($i = 0; $i < $len; $i++) {
            $result .= chr(ord($a[$i]) ^ ord($b[$i]));
        }

        return bin2hex($result);
    }

    /**
     * Determine which k-bucket this node belongs to relative to our ID.
     * Returns the bucket index (0 = closest, 127 = farthest).
     */
    public static function bucketIndex(string $localId, string $remoteId): int
    {
        $distance = self::xorDistance($localId, $remoteId);
        $bytes = hex2bin($distance);

        if ($bytes === false) {
            return 127;
        }

        // Find the position of the highest set bit
        for ($i = 0; $i < strlen($bytes); $i++) {
            $byte = ord($bytes[$i]);
            if ($byte === 0) {
                continue;
            }
            // Find highest bit in this byte
            $bit = 7;
            while ($bit >= 0 && !(($byte >> $bit) & 1)) {
                $bit--;
            }
            return (strlen($bytes) - 1 - $i) * 8 + $bit;
        }

        return 0; // Same node
    }
}
