<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Upgrade;

/**
 * SQLite persistence for upgrade history.
 * Seneschal owns this DB since it survives emperor failover.
 */
class UpgradeDatabase
{
    private \PDO $pdo;

    public function __construct(string $dbPath)
    {
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->pdo = new \PDO("sqlite:{$dbPath}", null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA synchronous=NORMAL');

        $this->migrate();
    }

    private function migrate(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS upgrade_history (
                id TEXT PRIMARY KEY,
                from_commit TEXT NOT NULL,
                to_commit TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT \'pending\',
                initiated_by TEXT NOT NULL,
                failure_reason TEXT NOT NULL DEFAULT \'\',
                nodes_total INTEGER NOT NULL DEFAULT 0,
                nodes_updated INTEGER NOT NULL DEFAULT 0,
                nodes_rolled_back INTEGER NOT NULL DEFAULT 0,
                started_at TEXT NOT NULL,
                completed_at TEXT NOT NULL DEFAULT \'\'
            )
        ');
    }

    public function insert(UpgradeHistory $entry): bool
    {
        $stmt = $this->pdo->prepare('
            INSERT OR IGNORE INTO upgrade_history
                (id, from_commit, to_commit, status, initiated_by, failure_reason,
                 nodes_total, nodes_updated, nodes_rolled_back, started_at, completed_at)
            VALUES
                (:id, :from_commit, :to_commit, :status, :initiated_by, :failure_reason,
                 :nodes_total, :nodes_updated, :nodes_rolled_back, :started_at, :completed_at)
        ');

        return $stmt->execute([
            ':id' => $entry->id,
            ':from_commit' => $entry->fromCommit,
            ':to_commit' => $entry->toCommit,
            ':status' => $entry->status,
            ':initiated_by' => $entry->initiatedBy,
            ':failure_reason' => $entry->failureReason,
            ':nodes_total' => $entry->nodesTotal,
            ':nodes_updated' => $entry->nodesUpdated,
            ':nodes_rolled_back' => $entry->nodesRolledBack,
            ':started_at' => $entry->startedAt,
            ':completed_at' => $entry->completedAt,
        ]);
    }

    public function update(UpgradeHistory $entry): bool
    {
        $stmt = $this->pdo->prepare('
            UPDATE upgrade_history SET
                status = :status,
                failure_reason = :failure_reason,
                nodes_total = :nodes_total,
                nodes_updated = :nodes_updated,
                nodes_rolled_back = :nodes_rolled_back,
                completed_at = :completed_at
            WHERE id = :id
        ');

        return $stmt->execute([
            ':id' => $entry->id,
            ':status' => $entry->status,
            ':failure_reason' => $entry->failureReason,
            ':nodes_total' => $entry->nodesTotal,
            ':nodes_updated' => $entry->nodesUpdated,
            ':nodes_rolled_back' => $entry->nodesRolledBack,
            ':completed_at' => $entry->completedAt,
        ]);
    }

    public function get(string $id): ?UpgradeHistory
    {
        $stmt = $this->pdo->prepare('SELECT * FROM upgrade_history WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ? UpgradeHistory::fromArray($row) : null;
    }

    /**
     * @return UpgradeHistory[]
     */
    public function getAll(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM upgrade_history ORDER BY started_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return array_map(fn(array $row) => UpgradeHistory::fromArray($row), $stmt->fetchAll());
    }

    public function getLatest(): ?UpgradeHistory
    {
        $stmt = $this->pdo->query('SELECT * FROM upgrade_history ORDER BY started_at DESC LIMIT 1');
        $row = $stmt->fetch();
        return $row ? UpgradeHistory::fromArray($row) : null;
    }
}
