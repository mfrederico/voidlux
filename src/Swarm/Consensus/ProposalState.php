<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Consensus;

enum ProposalState: string
{
    case Pending = 'pending';
    case Voting = 'voting';
    case Committed = 'committed';
    case Aborted = 'aborted';
    case Expired = 'expired';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Committed, self::Aborted, self::Expired => true,
            default => false,
        };
    }
}
