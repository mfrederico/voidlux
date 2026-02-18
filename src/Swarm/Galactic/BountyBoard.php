<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Galactic;

/**
 * Shared bounty board that persists across the broker network.
 * Each Seneschal maintains an in-memory copy; gossip keeps them in sync.
 *
 * Uses LWW (Last-Writer-Wins) via Lamport timestamps for convergence.
 * Works with the BountyModel and CapabilityProfile defined by the
 * cross-swarm wire protocol.
 */
class BountyBoard
{
    /** @var array<string, BountyModel> bounty_id => BountyModel */
    private array $bounties = [];

    /** @var array<string, CapabilityProfile> node_id => CapabilityProfile */
    private array $capabilities = [];

    public function postBounty(BountyModel $bounty): void
    {
        $this->bounties[$bounty->id] = $bounty;
    }

    /**
     * Receive a bounty from a remote peer. LWW: only accept if higher lamport_ts.
     */
    public function receiveBounty(BountyModel $bounty): bool
    {
        $existing = $this->bounties[$bounty->id] ?? null;
        if ($existing && $existing->lamportTs >= $bounty->lamportTs) {
            return false;
        }
        $this->bounties[$bounty->id] = $bounty;
        return true;
    }

    /**
     * Claim a bounty. Only succeeds if open and lamport_ts is higher.
     */
    public function claimBounty(string $bountyId, string $nodeId, int $lamportTs): ?BountyModel
    {
        $bounty = $this->bounties[$bountyId] ?? null;
        if (!$bounty || !$bounty->isOpen()) {
            return null;
        }
        if ($lamportTs <= $bounty->lamportTs) {
            return null;
        }
        $claimed = self::withLamportTs($bounty->withClaim($nodeId), $lamportTs);
        $this->bounties[$bountyId] = $claimed;
        return $claimed;
    }

    /**
     * Cancel a bounty. Only the original poster can cancel.
     */
    public function cancelBounty(string $bountyId, string $originNodeId, int $lamportTs): ?BountyModel
    {
        $bounty = $this->bounties[$bountyId] ?? null;
        if (!$bounty) {
            return null;
        }
        if ($bounty->postedByNodeId !== $originNodeId || $lamportTs <= $bounty->lamportTs) {
            return null;
        }
        $cancelled = self::withLamportTs($bounty->withStatus('cancelled'), $lamportTs);
        $this->bounties[$bountyId] = $cancelled;
        return $cancelled;
    }

    /**
     * Update a node's capability profile. LWW: only accept if higher lamport_ts.
     */
    public function updateCapability(CapabilityProfile $profile): bool
    {
        $existing = $this->capabilities[$profile->nodeId] ?? null;
        if ($existing && $existing->lamportTs >= $profile->lamportTs) {
            return false;
        }
        $this->capabilities[$profile->nodeId] = $profile;
        return true;
    }

    /**
     * @return BountyModel[]
     */
    public function getOpenBounties(): array
    {
        $this->purgeExpired();
        return array_values(array_filter(
            $this->bounties,
            fn(BountyModel $b) => $b->isOpen(),
        ));
    }

    /**
     * @return BountyModel[]
     */
    public function getAllBounties(): array
    {
        $this->purgeExpired();
        return array_values($this->bounties);
    }

    public function getBounty(string $id): ?BountyModel
    {
        return $this->bounties[$id] ?? null;
    }

    /**
     * @return CapabilityProfile[]
     */
    public function getCapabilities(): array
    {
        return array_values($this->capabilities);
    }

    /**
     * Find nodes capable of handling tasks with the given requirements.
     * Sorted by acceptance rate descending, then idle agents descending.
     */
    public function findCapableNodes(array $requiredCapabilities = []): array
    {
        $matches = array_filter(
            $this->capabilities,
            fn(CapabilityProfile $p) => $p->matchesCapabilities($requiredCapabilities) && $p->hasCapacity(),
        );
        usort($matches, function (CapabilityProfile $a, CapabilityProfile $b) {
            if ($a->acceptanceRate !== $b->acceptanceRate) {
                return $b->acceptanceRate <=> $a->acceptanceRate;
            }
            return $b->idleAgents <=> $a->idleAgents;
        });
        return $matches;
    }

    /**
     * Build a full snapshot for anti-entropy sync.
     */
    public function buildSnapshot(): array
    {
        $this->purgeExpired();
        return [
            'bounties' => array_map(fn(BountyModel $b) => $b->toArray(), $this->bounties),
            'capabilities' => array_map(fn(CapabilityProfile $p) => $p->toArray(), $this->capabilities),
        ];
    }

    /**
     * Merge a remote snapshot (anti-entropy). LWW for each entry.
     */
    public function mergeSnapshot(array $snapshot): void
    {
        foreach ($snapshot['bounties'] ?? [] as $data) {
            $bounty = BountyModel::fromArray($data);
            $this->receiveBounty($bounty);
        }
        foreach ($snapshot['capabilities'] ?? [] as $data) {
            $profile = CapabilityProfile::fromArray($data);
            $this->updateCapability($profile);
        }
    }

    /**
     * Create a new BountyModel with an updated lamport_ts.
     * BountyModel is immutable, so we round-trip through array.
     */
    private static function withLamportTs(BountyModel $bounty, int $ts): BountyModel
    {
        return BountyModel::fromArray(array_merge($bounty->toArray(), ['lamport_ts' => $ts]));
    }

    private function purgeExpired(): void
    {
        $this->bounties = array_filter(
            $this->bounties,
            fn(BountyModel $b) => !$b->isExpired() || $b->status !== 'open',
        );
    }
}
