<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Git;

class MergeResult
{
    public function __construct(
        public readonly bool $success,
        public readonly array $mergedBranches = [],
        public readonly array $conflictingBranches = [],
        public readonly string $conflictOutput = '',
    ) {}
}
