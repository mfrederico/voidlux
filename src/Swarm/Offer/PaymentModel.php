<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Offer;

class PaymentModel
{
    public function __construct(
        public readonly string $id,
        public readonly string $offerId,
        public readonly string $fromNodeId,
        public readonly string $toNodeId,
        public readonly int $amount,
        public readonly string $currency,
        public readonly PaymentStatus $status,
        public readonly string $txHash,
        public readonly int $lamportTs,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?string $failureReason = null,
    ) {}

    public static function create(
        string $offerId,
        string $fromNodeId,
        string $toNodeId,
        int $amount,
        int $lamportTs,
        string $currency = 'VOID',
    ): self {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return new self(
            id: self::generateUuid(),
            offerId: $offerId,
            fromNodeId: $fromNodeId,
            toNodeId: $toNodeId,
            amount: $amount,
            currency: $currency,
            status: PaymentStatus::Initiated,
            txHash: self::generateTxHash(),
            lamportTs: $lamportTs,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            offerId: $data['offer_id'],
            fromNodeId: $data['from_node_id'],
            toNodeId: $data['to_node_id'],
            amount: (int) ($data['amount'] ?? 0),
            currency: $data['currency'] ?? 'VOID',
            status: PaymentStatus::from($data['status'] ?? 'initiated'),
            txHash: $data['tx_hash'] ?? '',
            lamportTs: (int) ($data['lamport_ts'] ?? 0),
            createdAt: $data['created_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
            updatedAt: $data['updated_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
            failureReason: $data['failure_reason'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'offer_id' => $this->offerId,
            'from_node_id' => $this->fromNodeId,
            'to_node_id' => $this->toNodeId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'tx_hash' => $this->txHash,
            'lamport_ts' => $this->lamportTs,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'failure_reason' => $this->failureReason,
        ];
    }

    public function withStatus(PaymentStatus $status, int $lamportTs, ?string $failureReason = null): self
    {
        return new self(
            id: $this->id,
            offerId: $this->offerId,
            fromNodeId: $this->fromNodeId,
            toNodeId: $this->toNodeId,
            amount: $this->amount,
            currency: $this->currency,
            status: $status,
            txHash: $this->txHash,
            lamportTs: $lamportTs,
            createdAt: $this->createdAt,
            updatedAt: gmdate('Y-m-d\TH:i:s\Z'),
            failureReason: $failureReason ?? $this->failureReason,
        );
    }

    private static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private static function generateTxHash(): string
    {
        return '0x' . bin2hex(random_bytes(32));
    }
}
