<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Galactic;

/**
 * A bounty is a cross-swarm task posting with a reward.
 *
 * Any swarm can post a bounty describing work it needs done.
 * Other swarms can claim the bounty, execute the work, and
 * receive the reward upon completion.
 */
class BountyModel
{
    public function __construct(
        public readonly string $id,
        public readonly string $postedByNodeId,
        public readonly string $title,
        public readonly string $description,
        public readonly array $requiredCapabilities,
        public readonly int $reward,
        public readonly string $currency,
        public readonly string $status,
        public readonly ?string $claimedByNodeId,
        public readonly ?string $delegationId,
        public readonly int $lamportTs,
        public readonly string $expiresAt,
        public readonly string $createdAt,
    ) {}

    public static function create(
        string $postedByNodeId,
        string $title,
        string $description,
        array $requiredCapabilities = [],
        int $reward = 10,
        string $currency = 'VOID',
        int $ttlSeconds = 3600,
        int $lamportTs = 0,
    ): self {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return new self(
            id: self::generateUuid(),
            postedByNodeId: $postedByNodeId,
            title: $title,
            description: $description,
            requiredCapabilities: $requiredCapabilities,
            reward: $reward,
            currency: $currency,
            status: 'open',
            claimedByNodeId: null,
            delegationId: null,
            lamportTs: $lamportTs,
            expiresAt: gmdate('Y-m-d\TH:i:s\Z', time() + $ttlSeconds),
            createdAt: $now,
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            postedByNodeId: $data['posted_by_node_id'],
            title: $data['title'] ?? '',
            description: $data['description'] ?? '',
            requiredCapabilities: is_string($data['required_capabilities'] ?? '[]')
                ? json_decode($data['required_capabilities'], true) ?? []
                : ($data['required_capabilities'] ?? []),
            reward: (int) ($data['reward'] ?? 0),
            currency: $data['currency'] ?? 'VOID',
            status: $data['status'] ?? 'open',
            claimedByNodeId: $data['claimed_by_node_id'] ?? null,
            delegationId: $data['delegation_id'] ?? null,
            lamportTs: (int) ($data['lamport_ts'] ?? 0),
            expiresAt: $data['expires_at'] ?? '',
            createdAt: $data['created_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'posted_by_node_id' => $this->postedByNodeId,
            'title' => $this->title,
            'description' => $this->description,
            'required_capabilities' => $this->requiredCapabilities,
            'reward' => $this->reward,
            'currency' => $this->currency,
            'status' => $this->status,
            'claimed_by_node_id' => $this->claimedByNodeId,
            'delegation_id' => $this->delegationId,
            'lamport_ts' => $this->lamportTs,
            'expires_at' => $this->expiresAt,
            'created_at' => $this->createdAt,
        ];
    }

    public function withStatus(string $status): self
    {
        return new self(
            id: $this->id,
            postedByNodeId: $this->postedByNodeId,
            title: $this->title,
            description: $this->description,
            requiredCapabilities: $this->requiredCapabilities,
            reward: $this->reward,
            currency: $this->currency,
            status: $status,
            claimedByNodeId: $this->claimedByNodeId,
            delegationId: $this->delegationId,
            lamportTs: $this->lamportTs,
            expiresAt: $this->expiresAt,
            createdAt: $this->createdAt,
        );
    }

    public function withClaim(string $claimedByNodeId): self
    {
        return new self(
            id: $this->id,
            postedByNodeId: $this->postedByNodeId,
            title: $this->title,
            description: $this->description,
            requiredCapabilities: $this->requiredCapabilities,
            reward: $this->reward,
            currency: $this->currency,
            status: 'claimed',
            claimedByNodeId: $claimedByNodeId,
            delegationId: $this->delegationId,
            lamportTs: $this->lamportTs,
            expiresAt: $this->expiresAt,
            createdAt: $this->createdAt,
        );
    }

    public function withDelegation(string $delegationId): self
    {
        return new self(
            id: $this->id,
            postedByNodeId: $this->postedByNodeId,
            title: $this->title,
            description: $this->description,
            requiredCapabilities: $this->requiredCapabilities,
            reward: $this->reward,
            currency: $this->currency,
            status: $this->status,
            claimedByNodeId: $this->claimedByNodeId,
            delegationId: $delegationId,
            lamportTs: $this->lamportTs,
            expiresAt: $this->expiresAt,
            createdAt: $this->createdAt,
        );
    }

    public function isExpired(): bool
    {
        return strtotime($this->expiresAt) < time();
    }

    public function isOpen(): bool
    {
        return $this->status === 'open' && !$this->isExpired();
    }

    private static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
