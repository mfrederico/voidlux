<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Model;

enum TaskStatus: string
{
    case Pending = 'pending';
    case Claimed = 'claimed';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            default => false,
        };
    }

    public function isActive(): bool
    {
        return match ($this) {
            self::Claimed, self::InProgress => true,
            default => false,
        };
    }
}
