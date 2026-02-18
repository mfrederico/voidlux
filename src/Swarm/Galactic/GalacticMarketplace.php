<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Galactic;

class GalacticMarketplace
{
    /** @var array<string, OfferingModel> */
    private array $offerings = [];

    /** @var array<string, TributeModel> */
    private array $tributes = [];

    /** @var array<string, BountyModel> */
    private array $bounties = [];

    /** @var array<string, CapabilityProfile> Indexed by node_id */
    private array $capabilityProfiles = [];

    /** @var array<string, TaskDelegation> */
    private array $delegations = [];

    private int $walletBalance = 1000;

    /** @var int Local task completion counter for capability tracking */
    private int $localTasksCompleted = 0;

    /** @var int Local task failure counter for capability tracking */
    private int $localTasksFailed = 0;

    /** @var float Running average of task completion time in seconds */
    private float $avgCompletionSeconds = 0;

    public function __construct(
        private readonly string $nodeId,
    ) {}

    // ─── Offerings ─────────────────────────────────────────────

    public function announceOffering(int $idleAgents, array $capabilities = []): OfferingModel
    {
        $offering = OfferingModel::create(
            nodeId: $this->nodeId,
            idleAgents: $idleAgents,
            capabilities: $capabilities,
        );
        $this->offerings[$offering->id] = $offering;
        return $offering;
    }

    public function withdrawOffering(string $offeringId): bool
    {
        if (!isset($this->offerings[$offeringId])) {
            return false;
        }
        if ($this->offerings[$offeringId]->nodeId !== $this->nodeId) {
            return false;
        }
        unset($this->offerings[$offeringId]);
        return true;
    }

    public function receiveOffering(array $data): ?OfferingModel
    {
        $offering = OfferingModel::fromArray($data);
        if ($offering->nodeId === $this->nodeId) {
            return null;
        }
        $this->offerings[$offering->id] = $offering;
        return $offering;
    }

    public function receiveWithdraw(array $data): bool
    {
        $id = $data['offering_id'] ?? '';
        if (isset($this->offerings[$id])) {
            unset($this->offerings[$id]);
            return true;
        }
        return false;
    }

    /**
     * @return OfferingModel[]
     */
    public function getOfferings(): array
    {
        // Purge expired
        $this->offerings = array_filter(
            $this->offerings,
            fn(OfferingModel $o) => !$o->isExpired(),
        );
        return array_values($this->offerings);
    }

    // ─── Tributes ──────────────────────────────────────────────

    public function requestTribute(string $offeringId, int $taskCount): ?TributeModel
    {
        $offering = $this->offerings[$offeringId] ?? null;
        if (!$offering || $offering->isExpired()) {
            return null;
        }

        $tribute = TributeModel::create(
            offeringId: $offeringId,
            fromNodeId: $this->nodeId,
            toNodeId: $offering->nodeId,
            taskCount: $taskCount,
            pricePerTask: $offering->pricePerTask,
            currency: $offering->currency,
        );

        $this->tributes[$tribute->id] = $tribute;
        return $tribute;
    }

    public function receiveTributeRequest(array $data): ?TributeModel
    {
        $tribute = TributeModel::fromArray($data);
        if (isset($this->tributes[$tribute->id])) {
            return null; // Already have this tribute
        }
        $this->tributes[$tribute->id] = $tribute;
        return $tribute;
    }

    public function acceptTribute(string $tributeId): bool
    {
        $tribute = $this->tributes[$tributeId] ?? null;
        if (!$tribute || $tribute->status !== 'pending') {
            return false;
        }
        $this->tributes[$tributeId] = $tribute->withStatus('accepted');
        return true;
    }

    public function rejectTribute(string $tributeId): bool
    {
        $tribute = $this->tributes[$tributeId] ?? null;
        if (!$tribute || $tribute->status !== 'pending') {
            return false;
        }
        $this->tributes[$tributeId] = $tribute->withStatus('rejected');
        return true;
    }

    /**
     * @return TributeModel[]
     */
    public function getTributes(): array
    {
        return array_values($this->tributes);
    }

    // ─── Bounties ──────────────────────────────────────────────

    public function postBounty(
        string $title,
        string $description,
        array $requiredCapabilities = [],
        int $reward = 10,
        string $currency = 'VOID',
        int $ttlSeconds = 3600,
        int $lamportTs = 0,
    ): BountyModel {
        $bounty = BountyModel::create(
            postedByNodeId: $this->nodeId,
            title: $title,
            description: $description,
            requiredCapabilities: $requiredCapabilities,
            reward: $reward,
            currency: $currency,
            ttlSeconds: $ttlSeconds,
            lamportTs: $lamportTs,
        );
        $this->bounties[$bounty->id] = $bounty;
        return $bounty;
    }

    public function receiveBounty(array $data): ?BountyModel
    {
        $bounty = BountyModel::fromArray($data);
        if (isset($this->bounties[$bounty->id])) {
            return null; // Already have this bounty
        }
        if ($bounty->postedByNodeId === $this->nodeId) {
            return null; // Don't ingest our own bounty from gossip
        }
        $this->bounties[$bounty->id] = $bounty;
        return $bounty;
    }

    public function claimBounty(string $bountyId, string $claimerNodeId): bool
    {
        $bounty = $this->bounties[$bountyId] ?? null;
        if (!$bounty || !$bounty->isOpen()) {
            return false;
        }
        $this->bounties[$bountyId] = $bounty->withClaim($claimerNodeId);
        return true;
    }

    public function cancelBounty(string $bountyId): bool
    {
        $bounty = $this->bounties[$bountyId] ?? null;
        if (!$bounty || $bounty->status === 'completed') {
            return false;
        }
        $this->bounties[$bountyId] = $bounty->withStatus('cancelled');
        return true;
    }

    /**
     * @return BountyModel[]
     */
    public function getBounties(): array
    {
        // Purge expired open bounties
        $this->bounties = array_filter(
            $this->bounties,
            fn(BountyModel $b) => !($b->status === 'open' && $b->isExpired()),
        );
        return array_values($this->bounties);
    }

    public function getBounty(string $bountyId): ?BountyModel
    {
        return $this->bounties[$bountyId] ?? null;
    }

    // ─── Capability Profiles ───────────────────────────────────

    /**
     * Build and return this node's capability profile.
     */
    public function buildLocalProfile(int $idleAgents, int $totalAgents, array $capabilities, int $lamportTs): CapabilityProfile
    {
        return CapabilityProfile::create(
            nodeId: $this->nodeId,
            capabilities: $capabilities,
            tasksCompleted: $this->localTasksCompleted,
            tasksFailed: $this->localTasksFailed,
            avgCompletionSeconds: $this->avgCompletionSeconds,
            idleAgents: $idleAgents,
            totalAgents: $totalAgents,
            lamportTs: $lamportTs,
        );
    }

    public function receiveCapabilityProfile(CapabilityProfile $profile): void
    {
        $existing = $this->capabilityProfiles[$profile->nodeId] ?? null;
        // Only update if newer (higher lamport timestamp)
        if (!$existing || $profile->lamportTs > $existing->lamportTs) {
            $this->capabilityProfiles[$profile->nodeId] = $profile;
        }
    }

    /**
     * @return CapabilityProfile[]
     */
    public function getCapabilityProfiles(): array
    {
        return array_values($this->capabilityProfiles);
    }

    public function getCapabilityProfile(string $nodeId): ?CapabilityProfile
    {
        return $this->capabilityProfiles[$nodeId] ?? null;
    }

    /**
     * Find nodes that match required capabilities, sorted by acceptance rate.
     *
     * @return CapabilityProfile[]
     */
    public function findCapableNodes(array $requiredCapabilities, bool $requireCapacity = true): array
    {
        $matches = [];
        foreach ($this->capabilityProfiles as $profile) {
            if ($profile->nodeId === $this->nodeId) {
                continue; // Skip self
            }
            if (!$profile->matchesCapabilities($requiredCapabilities)) {
                continue;
            }
            if ($requireCapacity && !$profile->hasCapacity()) {
                continue;
            }
            $matches[] = $profile;
        }

        // Sort by acceptance rate descending (best performers first)
        usort($matches, fn(CapabilityProfile $a, CapabilityProfile $b) =>
            $b->acceptanceRate <=> $a->acceptanceRate
            ?: $b->idleAgents <=> $a->idleAgents
        );

        return $matches;
    }

    /**
     * Record a local task completion for acceptance rate tracking.
     */
    public function recordTaskCompletion(float $durationSeconds): void
    {
        $this->localTasksCompleted++;
        $total = $this->localTasksCompleted + $this->localTasksFailed;
        // Running average
        $this->avgCompletionSeconds = (($this->avgCompletionSeconds * ($this->localTasksCompleted - 1)) + $durationSeconds) / $this->localTasksCompleted;
    }

    /**
     * Record a local task failure for acceptance rate tracking.
     */
    public function recordTaskFailure(): void
    {
        $this->localTasksFailed++;
    }

    // ─── Task Delegations ──────────────────────────────────────

    public function createDelegation(
        string $targetNodeId,
        string $title,
        string $description,
        ?string $workInstructions = null,
        ?string $acceptanceCriteria = null,
        array $requiredCapabilities = [],
        ?string $projectPath = null,
        ?string $bountyId = null,
        ?string $tributeId = null,
        int $lamportTs = 0,
    ): TaskDelegation {
        $delegation = TaskDelegation::create(
            sourceNodeId: $this->nodeId,
            targetNodeId: $targetNodeId,
            title: $title,
            description: $description,
            workInstructions: $workInstructions,
            acceptanceCriteria: $acceptanceCriteria,
            requiredCapabilities: $requiredCapabilities,
            projectPath: $projectPath,
            bountyId: $bountyId,
            tributeId: $tributeId,
            lamportTs: $lamportTs,
        );
        $this->delegations[$delegation->id] = $delegation;
        return $delegation;
    }

    public function receiveDelegation(TaskDelegation $delegation): void
    {
        $this->delegations[$delegation->id] = $delegation;
    }

    public function acceptDelegation(string $delegationId, string $remoteTaskId): bool
    {
        $delegation = $this->delegations[$delegationId] ?? null;
        if (!$delegation || $delegation->status !== 'requested') {
            return false;
        }
        $this->delegations[$delegationId] = $delegation->withAccepted($remoteTaskId);
        return true;
    }

    public function rejectDelegation(string $delegationId, string $reason): bool
    {
        $delegation = $this->delegations[$delegationId] ?? null;
        if (!$delegation || $delegation->status !== 'requested') {
            return false;
        }
        $this->delegations[$delegationId] = $delegation->withStatus('rejected');
        return true;
    }

    public function completeDelegation(string $delegationId, string $result): bool
    {
        $delegation = $this->delegations[$delegationId] ?? null;
        if (!$delegation) {
            return false;
        }
        $this->delegations[$delegationId] = $delegation->withResult($result);
        return true;
    }

    public function failDelegation(string $delegationId, string $error): bool
    {
        $delegation = $this->delegations[$delegationId] ?? null;
        if (!$delegation) {
            return false;
        }
        $this->delegations[$delegationId] = $delegation->withError($error);
        return true;
    }

    /**
     * @return TaskDelegation[]
     */
    public function getDelegations(): array
    {
        return array_values($this->delegations);
    }

    public function getDelegation(string $delegationId): ?TaskDelegation
    {
        return $this->delegations[$delegationId] ?? null;
    }

    // ─── Wallet ────────────────────────────────────────────────

    public function getWallet(): array
    {
        return [
            'balance' => $this->walletBalance,
            'currency' => 'VOID',
        ];
    }

    public function getNodeId(): string
    {
        return $this->nodeId;
    }
}
