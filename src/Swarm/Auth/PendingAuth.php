<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Auth;

/**
 * Tracks a pending authentication challenge for a connection.
 */
class PendingAuth
{
    public function __construct(
        public readonly string $nonce,
        public readonly string $peerNodeId,
        public readonly string $peerRole,
        public readonly float $createdAt,
    ) {}
}
