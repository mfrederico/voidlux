<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Git;

class TestResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $output = '',
        public readonly int $exitCode = 0,
    ) {}
}
