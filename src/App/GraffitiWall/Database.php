<?php

declare(strict_types=1);

namespace VoidLux\App\GraffitiWall;

/**
 * SQLite database for the graffiti wall.
 * Pattern from myctobot's AppDatastore.
 */
class Database
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
            CREATE TABLE IF NOT EXISTS posts (
                id TEXT PRIMARY KEY,
                content TEXT NOT NULL,
                author TEXT NOT NULL,
                node_id TEXT NOT NULL,
                lamport_ts INTEGER NOT NULL,
                created_at TEXT NOT NULL,
                received_at TEXT NOT NULL
            )
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS node_state (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )
        ');

        $this->pdo->exec('
            CREATE INDEX IF NOT EXISTS idx_posts_lamport ON posts(lamport_ts)
        ');
    }

    public function insertPost(PostModel $post): bool
    {
        $stmt = $this->pdo->prepare('
            INSERT OR IGNORE INTO posts (id, content, author, node_id, lamport_ts, created_at, received_at)
            VALUES (:id, :content, :author, :node_id, :lamport_ts, :created_at, :received_at)
        ');

        return $stmt->execute([
            ':id' => $post->id,
            ':content' => $post->content,
            ':author' => $post->author,
            ':node_id' => $post->nodeId,
            ':lamport_ts' => $post->lamportTs,
            ':created_at' => $post->createdAt,
            ':received_at' => $post->receivedAt,
        ]);
    }

    public function hasPost(string $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM posts WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Get all posts ordered by lamport timestamp descending.
     * @return PostModel[]
     */
    public function getAllPosts(): array
    {
        $rows = $this->pdo->query('SELECT * FROM posts ORDER BY lamport_ts DESC, created_at DESC')->fetchAll();
        return array_map(fn(array $row) => PostModel::fromArray($row), $rows);
    }

    /**
     * Get posts with lamport_ts greater than the given value.
     * @return PostModel[]
     */
    public function getPostsSince(int $lamportTs): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM posts WHERE lamport_ts > :ts ORDER BY lamport_ts ASC');
        $stmt->execute([':ts' => $lamportTs]);
        return array_map(fn(array $row) => PostModel::fromArray($row), $stmt->fetchAll());
    }

    public function getPostCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();
    }

    public function getMaxLamportTs(): int
    {
        $val = $this->pdo->query('SELECT COALESCE(MAX(lamport_ts), 0) FROM posts')->fetchColumn();
        return (int) $val;
    }

    public function getState(string $key, string $default = ''): string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM node_state WHERE key = :key');
        $stmt->execute([':key' => $key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? $val : $default;
    }

    public function setState(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare('INSERT OR REPLACE INTO node_state (key, value) VALUES (:key, :value)');
        $stmt->execute([':key' => $key, ':value' => $value]);
    }
}
