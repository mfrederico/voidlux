<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Broker;

/**
 * A message buffered in the broker queue awaiting delivery.
 */
class QueuedMessage
{
    public function __construct(
        public readonly string $targetNodeId,
        public readonly array $message,
        public readonly int $priority,
        public readonly float $enqueuedAt,
        public readonly float $ttl,
    ) {}

    public function isExpired(): bool
    {
        return (microtime(true) - $this->enqueuedAt) > $this->ttl;
    }

    public function age(): float
    {
        return microtime(true) - $this->enqueuedAt;
    }
}
