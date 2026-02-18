<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Galactic;

class OfferingModel
{
    public function __construct(
        public readonly string $id,
        public readonly string $nodeId,
        public readonly int $idleAgents,
        public readonly array $capabilities,
        public readonly int $pricePerTask,
        public readonly string $currency,
        public readonly string $expiresAt,
        public readonly string $createdAt,
    ) {}

    public static function create(
        string $nodeId,
        int $idleAgents,
        array $capabilities = [],
        int $pricePerTask = 1,
        string $currency = 'VOID',
        int $ttlSeconds = 300,
    ): self {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $expires = gmdate('Y-m-d\TH:i:s\Z', time() + $ttlSeconds);
        return new self(
            id: self::generateUuid(),
            nodeId: $nodeId,
            idleAgents: $idleAgents,
            capabilities: $capabilities,
            pricePerTask: $pricePerTask,
            currency: $currency,
            expiresAt: $expires,
            createdAt: $now,
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            nodeId: $data['node_id'],
            idleAgents: (int) ($data['idle_agents'] ?? 0),
            capabilities: is_string($data['capabilities'] ?? '[]')
                ? json_decode($data['capabilities'], true) ?? []
                : ($data['capabilities'] ?? []),
            pricePerTask: (int) ($data['price_per_task'] ?? 1),
            currency: $data['currency'] ?? 'VOID',
            expiresAt: $data['expires_at'] ?? '',
            createdAt: $data['created_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'node_id' => $this->nodeId,
            'idle_agents' => $this->idleAgents,
            'capabilities' => $this->capabilities,
            'price_per_task' => $this->pricePerTask,
            'currency' => $this->currency,
            'expires_at' => $this->expiresAt,
            'created_at' => $this->createdAt,
        ];
    }

    public function isExpired(): bool
    {
        return strtotime($this->expiresAt) < time();
    }

    private static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
