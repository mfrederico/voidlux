<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Offer;

enum OfferStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case PaymentPending = 'payment_pending';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Rejected, self::Expired, self::Paid, self::Cancelled => true,
            default => false,
        };
    }

    public function canAccept(): bool
    {
        return $this === self::Pending;
    }

    public function canReject(): bool
    {
        return $this === self::Pending;
    }

    public function canPay(): bool
    {
        return $this === self::Accepted || $this === self::PaymentPending;
    }
}
