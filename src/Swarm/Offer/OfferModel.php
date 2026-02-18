<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Offer;

class OfferModel
{
    public function __construct(
        public readonly string $id,
        public readonly string $fromNodeId,
        public readonly string $toNodeId,
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $conditions,
        public readonly OfferStatus $status,
        public readonly int $lamportTs,
        public readonly string $expiresAt,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?string $taskId = null,
        public readonly ?string $responseReason = null,
    ) {}

    public static function create(
        string $fromNodeId,
        string $toNodeId,
        int $amount,
        int $lamportTs,
        string $currency = 'VOID',
        string $conditions = '',
        int $validitySeconds = 300,
        ?string $taskId = null,
    ): self {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return new self(
            id: self::generateUuid(),
            fromNodeId: $fromNodeId,
            toNodeId: $toNodeId,
            amount: $amount,
            currency: $currency,
            conditions: $conditions,
            status: OfferStatus::Pending,
            lamportTs: $lamportTs,
            expiresAt: gmdate('Y-m-d\TH:i:s\Z', time() + $validitySeconds),
            createdAt: $now,
            updatedAt: $now,
            taskId: $taskId,
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            fromNodeId: $data['from_node_id'],
            toNodeId: $data['to_node_id'],
            amount: (int) ($data['amount'] ?? 0),
            currency: $data['currency'] ?? 'VOID',
            conditions: $data['conditions'] ?? '',
            status: OfferStatus::from($data['status'] ?? 'pending'),
            lamportTs: (int) ($data['lamport_ts'] ?? 0),
            expiresAt: $data['expires_at'] ?? '',
            createdAt: $data['created_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
            updatedAt: $data['updated_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
            taskId: $data['task_id'] ?? null,
            responseReason: $data['response_reason'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'from_node_id' => $this->fromNodeId,
            'to_node_id' => $this->toNodeId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'conditions' => $this->conditions,
            'status' => $this->status->value,
            'lamport_ts' => $this->lamportTs,
            'expires_at' => $this->expiresAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'task_id' => $this->taskId,
            'response_reason' => $this->responseReason,
        ];
    }

    public function withStatus(OfferStatus $status, int $lamportTs, ?string $reason = null): self
    {
        return new self(
            id: $this->id,
            fromNodeId: $this->fromNodeId,
            toNodeId: $this->toNodeId,
            amount: $this->amount,
            currency: $this->currency,
            conditions: $this->conditions,
            status: $status,
            lamportTs: $lamportTs,
            expiresAt: $this->expiresAt,
            createdAt: $this->createdAt,
            updatedAt: gmdate('Y-m-d\TH:i:s\Z'),
            taskId: $this->taskId,
            responseReason: $reason ?? $this->responseReason,
        );
    }

    public function isExpired(): bool
    {
        return strtotime($this->expiresAt) < time();
    }

    public function validate(): ?string
    {
        if ($this->amount <= 0) {
            return 'Offer amount must be positive';
        }
        if (empty($this->fromNodeId)) {
            return 'Sender node ID is required';
        }
        if (empty($this->toNodeId)) {
            return 'Recipient node ID is required';
        }
        if ($this->fromNodeId === $this->toNodeId) {
            return 'Cannot create offer to self';
        }
        if ($this->isExpired()) {
            return 'Offer has expired';
        }
        return null;
    }

    private static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
