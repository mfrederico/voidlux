<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Model;

enum TaskStatus: string
{
    case Pending = 'pending';
    case Blocked = 'blocked';
    case Planning = 'planning';
    case Claimed = 'claimed';
    case InProgress = 'in_progress';
    case PendingReview = 'pending_review';
    case Completed = 'completed';
    case Failed = 'failed';
    case WaitingInput = 'waiting_input';
    case Merging = 'merging';
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
            self::Blocked, self::Planning, self::Claimed, self::InProgress, self::PendingReview, self::WaitingInput, self::Merging => true,
            default => false,
        };
    }
}
