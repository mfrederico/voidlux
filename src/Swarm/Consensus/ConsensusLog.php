<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Consensus;

/**
 * Persistent replicated log of committed consensus decisions.
 *
 * Each committed proposal becomes an entry in the log, providing an ordered
 * history of agreed-upon state changes. Used for:
 * - Replaying state after node restart
 * - Anti-entropy reconciliation between nodes
 * - Audit trail of consensus decisions
 *
 * Uses the same SQLite database as the swarm, with its own table.
 */
class ConsensusLog
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
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS consensus_log (
                id TEXT PRIMARY KEY,
                term INTEGER NOT NULL,
                log_index INTEGER NOT NULL,
                proposer_node_id TEXT NOT NULL,
                operation TEXT NOT NULL,
                payload TEXT NOT NULL,
                lamport_ts INTEGER NOT NULL,
                committed_at TEXT NOT NULL,
                created_at TEXT NOT NULL
            )
        ");
        $this->pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_consensus_log_index ON consensus_log(log_index)
        ");
        $this->pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_consensus_log_term ON consensus_log(term)
        ");
    }

    /**
     * Append a committed proposal to the log.
     */
    public function append(Proposal $proposal): int
    {
        $nextIndex = $this->getLastIndex() + 1;

        $stmt = $this->pdo->prepare("
            INSERT OR IGNORE INTO consensus_log (id, term, log_index, proposer_node_id, operation, payload, lamport_ts, committed_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $proposal->id,
            $proposal->term,
            $nextIndex,
            $proposal->proposerNodeId,
            $proposal->operation,
            json_encode($proposal->payload),
            $proposal->lamportTs,
            $proposal->committedAt ?? gmdate('Y-m-d\TH:i:s\Z'),
            $proposal->createdAt,
        ]);

        return $nextIndex;
    }

    /**
     * Get the last committed log index (0 if empty).
     */
    public function getLastIndex(): int
    {
        $stmt = $this->pdo->query("SELECT MAX(log_index) AS max_idx FROM consensus_log");
        $row = $stmt->fetch();
        return $row ? (int) ($row['max_idx'] ?? 0) : 0;
    }

    /**
     * Get the term of the last committed entry.
     */
    public function getLastTerm(): int
    {
        $stmt = $this->pdo->query("SELECT term FROM consensus_log ORDER BY log_index DESC LIMIT 1");
        $row = $stmt->fetch();
        return $row ? (int) ($row['term'] ?? 0) : 0;
    }

    /**
     * Get entries from a specific index onward (for replication/sync).
     * @return array[] Raw log entry arrays
     */
    public function getEntriesSince(int $afterIndex, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM consensus_log WHERE log_index > ? ORDER BY log_index ASC LIMIT ?
        ");
        $stmt->execute([$afterIndex, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Get a specific entry by index.
     */
    public function getEntry(int $index): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM consensus_log WHERE log_index = ?");
        $stmt->execute([$index]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Check if a proposal has already been committed (by proposal ID).
     */
    public function hasProposal(string $proposalId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM consensus_log WHERE id = ?");
        $stmt->execute([$proposalId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Get total committed entries count.
     */
    public function count(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) AS cnt FROM consensus_log");
        $row = $stmt->fetch();
        return $row ? (int) ($row['cnt'] ?? 0) : 0;
    }
}
