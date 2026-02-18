<?php

declare(strict_types=1);

namespace VoidLux\Identity;

/**
 * Result of an identity or credential verification operation.
 */
class VerificationResult
{
    public function __construct(
        public readonly bool $valid,
        public readonly string $reason,
    ) {}
}
