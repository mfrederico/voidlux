<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Auth;

/**
 * Result of an authentication verification attempt.
 */
class AuthResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $reason,
    ) {}
}
