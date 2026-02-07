<?php

declare(strict_types=1);

namespace VoidLux\P2P\Protocol;

/**
 * Lamport logical clock for causal ordering of events across nodes.
 */
class LamportClock
{
    private int $counter;

    public function __construct(int $initial = 0)
    {
        $this->counter = $initial;
    }

    /**
     * Increment clock for a local event. Returns the new value.
     */
    public function tick(): int
    {
        return ++$this->counter;
    }

    /**
     * Update clock upon receiving a remote timestamp.
     * Sets clock to max(local, remote) + 1.
     */
    public function witness(int $remote): int
    {
        $this->counter = max($this->counter, $remote) + 1;
        return $this->counter;
    }

    /**
     * Current clock value without incrementing.
     */
    public function value(): int
    {
        return $this->counter;
    }
}
