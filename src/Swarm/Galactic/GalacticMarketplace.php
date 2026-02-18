<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Galactic;

class GalacticMarketplace
{
    /** @var array<string, OfferingModel> */
    private array $offerings = [];

    /** @var array<string, TributeModel> */
    private array $tributes = [];

    private int $walletBalance = 1000;

    public function __construct(
        private readonly string $nodeId,
    ) {}

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
     * @return OfferingModel[]
     */
    public function getOfferings(): array
    {
        // Purge expired
        $now = time();
        $this->offerings = array_filter(
            $this->offerings,
            fn(OfferingModel $o) => !$o->isExpired(),
        );
        return array_values($this->offerings);
    }

    /**
     * @return TributeModel[]
     */
    public function getTributes(): array
    {
        return array_values($this->tributes);
    }

    public function getWallet(): array
    {
        return [
            'balance' => $this->walletBalance,
            'currency' => 'VOID',
        ];
    }
}
