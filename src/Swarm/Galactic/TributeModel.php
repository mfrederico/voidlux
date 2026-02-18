<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Galactic;

class TributeModel
{
    public function __construct(
        public readonly string $id,
        public readonly string $offeringId,
        public readonly string $fromNodeId,
        public readonly string $toNodeId,
        public readonly int $taskCount,
        public readonly int $totalCost,
        public readonly string $currency,
        public readonly string $status,
        public readonly string $txHash,
        public readonly string $createdAt,
    ) {}

    public static function create(
        string $offeringId,
        string $fromNodeId,
        string $toNodeId,
        int $taskCount,
        int $pricePerTask,
        string $currency = 'VOID',
    ): self {
        return new self(
            id: self::generateUuid(),
            offeringId: $offeringId,
            fromNodeId: $fromNodeId,
            toNodeId: $toNodeId,
            taskCount: $taskCount,
            totalCost: $taskCount * $pricePerTask,
            currency: $currency,
            status: 'pending',
            txHash: self::generateStubTxHash(),
            createdAt: gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            offeringId: $data['offering_id'],
            fromNodeId: $data['from_node_id'],
            toNodeId: $data['to_node_id'],
            taskCount: (int) ($data['task_count'] ?? 0),
            totalCost: (int) ($data['total_cost'] ?? 0),
            currency: $data['currency'] ?? 'VOID',
            status: $data['status'] ?? 'pending',
            txHash: $data['tx_hash'] ?? '',
            createdAt: $data['created_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'offering_id' => $this->offeringId,
            'from_node_id' => $this->fromNodeId,
            'to_node_id' => $this->toNodeId,
            'task_count' => $this->taskCount,
            'total_cost' => $this->totalCost,
            'currency' => $this->currency,
            'status' => $this->status,
            'tx_hash' => $this->txHash,
            'created_at' => $this->createdAt,
        ];
    }

    public function withStatus(string $status): self
    {
        return new self(
            id: $this->id,
            offeringId: $this->offeringId,
            fromNodeId: $this->fromNodeId,
            toNodeId: $this->toNodeId,
            taskCount: $this->taskCount,
            totalCost: $this->totalCost,
            currency: $this->currency,
            status: $status,
            txHash: $this->txHash,
            createdAt: $this->createdAt,
        );
    }

    public static function generateStubTxHash(): string
    {
        return '0x' . bin2hex(random_bytes(32));
    }

    private static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
