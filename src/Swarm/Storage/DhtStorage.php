<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Storage;

/**
 * Local SQLite storage for DHT entries on this node.
 * Each node stores a subset of the DHT; gossip replicates across peers.
 */
class DhtStorage
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->migrate();
    }

    private function migrate(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS dht_entries (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL DEFAULT \'\',
                content_hash TEXT NOT NULL,
                origin_node TEXT NOT NULL,
                lamport_ts INTEGER NOT NULL,
                replica_count INTEGER NOT NULL DEFAULT 3,
                ttl INTEGER NOT NULL DEFAULT 0,
                tombstone INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        ');

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_dht_lamport ON dht_entries(lamport_ts)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_dht_origin ON dht_entries(origin_node)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_dht_hash ON dht_entries(content_hash)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_dht_tombstone ON dht_entries(tombstone)');
    }

    public function put(DhtEntry $entry): bool
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO dht_entries
                (key, value, content_hash, origin_node, lamport_ts, replica_count, ttl, tombstone, created_at, updated_at)
            VALUES
                (:key, :value, :content_hash, :origin_node, :lamport_ts, :replica_count, :ttl, :tombstone, :created_at, :updated_at)
            ON CONFLICT(key) DO UPDATE SET
                value = CASE WHEN excluded.lamport_ts > dht_entries.lamport_ts THEN excluded.value ELSE dht_entries.value END,
                content_hash = CASE WHEN excluded.lamport_ts > dht_entries.lamport_ts THEN excluded.content_hash ELSE dht_entries.content_hash END,
                lamport_ts = CASE WHEN excluded.lamport_ts > dht_entries.lamport_ts THEN excluded.lamport_ts ELSE dht_entries.lamport_ts END,
                tombstone = CASE WHEN excluded.lamport_ts > dht_entries.lamport_ts THEN excluded.tombstone ELSE dht_entries.tombstone END,
                updated_at = CASE WHEN excluded.lamport_ts > dht_entries.lamport_ts THEN excluded.updated_at ELSE dht_entries.updated_at END
        ');

        return $stmt->execute([
            ':key' => $entry->key,
            ':value' => $entry->value,
            ':content_hash' => $entry->contentHash,
            ':origin_node' => $entry->originNode,
            ':lamport_ts' => $entry->lamportTs,
            ':replica_count' => $entry->replicaCount,
            ':ttl' => $entry->ttl,
            ':tombstone' => $entry->tombstone ? 1 : 0,
            ':created_at' => $entry->createdAt,
            ':updated_at' => $entry->updatedAt,
        ]);
    }

    public function get(string $key): ?DhtEntry
    {
        $stmt = $this->pdo->prepare('SELECT * FROM dht_entries WHERE key = :key AND tombstone = 0');
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch();
        return $row ? DhtEntry::fromArray($row) : null;
    }

    public function has(string $key): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM dht_entries WHERE key = :key');
        $stmt->execute([':key' => $key]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Get entry even if tombstoned (for replication conflict resolution).
     */
    public function getRaw(string $key): ?DhtEntry
    {
        $stmt = $this->pdo->prepare('SELECT * FROM dht_entries WHERE key = :key');
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch();
        return $row ? DhtEntry::fromArray($row) : null;
    }

    /**
     * Find entries by content hash (content-addressed lookup).
     * @return DhtEntry[]
     */
    public function findByContentHash(string $hash): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM dht_entries WHERE content_hash = :hash AND tombstone = 0');
        $stmt->execute([':hash' => $hash]);
        return array_map(fn(array $row) => DhtEntry::fromArray($row), $stmt->fetchAll());
    }

    /**
     * Get all entries with lamport_ts greater than the given value (for anti-entropy sync).
     * @return DhtEntry[]
     */
    public function getEntriesSince(int $lamportTs): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM dht_entries WHERE lamport_ts > :ts ORDER BY lamport_ts ASC');
        $stmt->execute([':ts' => $lamportTs]);
        return array_map(fn(array $row) => DhtEntry::fromArray($row), $stmt->fetchAll());
    }

    /**
     * Get all non-tombstoned entries.
     * @return DhtEntry[]
     */
    public function getAllLive(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM dht_entries WHERE tombstone = 0 ORDER BY lamport_ts ASC');
        return array_map(fn(array $row) => DhtEntry::fromArray($row), $stmt->fetchAll());
    }

    public function getMaxLamportTs(): int
    {
        return (int) $this->pdo->query('SELECT COALESCE(MAX(lamport_ts), 0) FROM dht_entries')->fetchColumn();
    }

    public function getEntryCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM dht_entries WHERE tombstone = 0')->fetchColumn();
    }

    public function getTotalSize(): int
    {
        return (int) $this->pdo->query('SELECT COALESCE(SUM(LENGTH(value)), 0) FROM dht_entries WHERE tombstone = 0')->fetchColumn();
    }

    /**
     * Purge expired entries and old tombstones.
     * Tombstones older than the given age (seconds) are removed.
     * @return int Number of entries purged
     */
    public function purgeExpired(int $tombstoneMaxAge = 300): int
    {
        $count = 0;

        // Purge TTL-expired entries
        $rows = $this->pdo->query('SELECT * FROM dht_entries WHERE ttl > 0 AND tombstone = 0')->fetchAll();
        foreach ($rows as $row) {
            $entry = DhtEntry::fromArray($row);
            if ($entry->isExpired()) {
                $this->pdo->prepare('DELETE FROM dht_entries WHERE key = :key')->execute([':key' => $entry->key]);
                $count++;
            }
        }

        // Purge old tombstones
        $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - $tombstoneMaxAge);
        $stmt = $this->pdo->prepare('DELETE FROM dht_entries WHERE tombstone = 1 AND updated_at < :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);
        $count += $stmt->rowCount();

        return $count;
    }
}
