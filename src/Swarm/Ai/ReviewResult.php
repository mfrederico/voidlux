<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Ai;

class ReviewResult
{
    public function __construct(
        public readonly bool $accepted,
        public readonly string $feedback,
    ) {}
}
