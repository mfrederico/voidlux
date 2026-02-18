<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Storage;

/**
 * Immutable data class representing a DHT key-value entry.
 *
 * Content-addressed storage: the key is SHA-256(value) for data integrity.
 * Arbitrary keys are also supported for named lookups.
 */
class DhtEntry
{
    public function __construct(
        public readonly string $key,
        public readonly string $value,
        public readonly string $contentHash,
        public readonly string $originNode,
        public readonly int $lamportTs,
        public readonly int $replicaCount,
        public readonly int $ttl,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly bool $tombstone = false,
    ) {}

    /**
     * Create a new DHT entry with content-addressed key (SHA-256 of value).
     */
    public static function createContentAddressed(
        string $value,
        string $originNode,
        int $lamportTs,
        int $replicaCount = 3,
        int $ttl = 0,
    ): self {
        $hash = hash('sha256', $value);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return new self(
            key: $hash,
            value: $value,
            contentHash: $hash,
            originNode: $originNode,
            lamportTs: $lamportTs,
            replicaCount: $replicaCount,
            ttl: $ttl,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * Create a new DHT entry with an arbitrary named key.
     */
    public static function createNamed(
        string $key,
        string $value,
        string $originNode,
        int $lamportTs,
        int $replicaCount = 3,
        int $ttl = 0,
    ): self {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return new self(
            key: $key,
            value: $value,
            contentHash: hash('sha256', $value),
            originNode: $originNode,
            lamportTs: $lamportTs,
            replicaCount: $replicaCount,
            ttl: $ttl,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            key: $data['key'],
            value: $data['value'],
            contentHash: $data['content_hash'] ?? hash('sha256', $data['value']),
            originNode: $data['origin_node'] ?? '',
            lamportTs: (int) ($data['lamport_ts'] ?? 0),
            replicaCount: (int) ($data['replica_count'] ?? 3),
            ttl: (int) ($data['ttl'] ?? 0),
            createdAt: $data['created_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
            updatedAt: $data['updated_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
            tombstone: !empty($data['tombstone']),
        );
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'value' => $this->value,
            'content_hash' => $this->contentHash,
            'origin_node' => $this->originNode,
            'lamport_ts' => $this->lamportTs,
            'replica_count' => $this->replicaCount,
            'ttl' => $this->ttl,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'tombstone' => $this->tombstone,
        ];
    }

    /**
     * Create a tombstone version of this entry for deletion propagation.
     */
    public function asTombstone(int $lamportTs): self
    {
        return new self(
            key: $this->key,
            value: '',
            contentHash: $this->contentHash,
            originNode: $this->originNode,
            lamportTs: $lamportTs,
            replicaCount: $this->replicaCount,
            ttl: $this->ttl,
            createdAt: $this->createdAt,
            updatedAt: gmdate('Y-m-d\TH:i:s\Z'),
            tombstone: true,
        );
    }

    /**
     * Verify data integrity: content hash matches stored value.
     */
    public function verifyIntegrity(): bool
    {
        if ($this->tombstone) {
            return true;
        }
        return hash('sha256', $this->value) === $this->contentHash;
    }

    /**
     * Check if this entry has expired (TTL-based).
     * TTL of 0 means no expiration.
     */
    public function isExpired(): bool
    {
        if ($this->ttl === 0) {
            return false;
        }
        $created = strtotime($this->createdAt);
        return $created !== false && (time() - $created) > $this->ttl;
    }
}
