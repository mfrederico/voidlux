<?php

declare(strict_types=1);

namespace VoidLux\P2P\Discovery;

/**
 * A node entry in the DHT routing table.
 */
class DhtNode
{
    public function __construct(
        public readonly string $nodeId,
        public readonly string $host,
        public readonly int $p2pPort,
        public readonly int $httpPort = 0,
        public readonly string $role = 'worker',
        public float $lastSeen = 0.0,
        public int $failCount = 0,
    ) {
        if ($this->lastSeen === 0.0) {
            $this->lastSeen = microtime(true);
        }
    }

    public function touch(): void
    {
        $this->lastSeen = microtime(true);
        $this->failCount = 0;
    }

    public function isStale(float $maxAge = 120.0): bool
    {
        return (microtime(true) - $this->lastSeen) > $maxAge;
    }
}
