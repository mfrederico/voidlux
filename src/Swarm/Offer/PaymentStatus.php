<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Offer;

enum PaymentStatus: string
{
    case Initiated = 'initiated';
    case Confirmed = 'confirmed';
    case Failed = 'failed';
    case Refunded = 'refunded';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Confirmed, self::Failed, self::Refunded => true,
            default => false,
        };
    }
}
