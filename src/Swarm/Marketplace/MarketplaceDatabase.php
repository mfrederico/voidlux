<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Marketplace;

/**
 * Galactic Marketplace - Plugin Economy System
 *
 * Agents can:
 * - Request plugins via Bounties
 * - Build and offer plugins
 * - Download and install plugins
 * - Rate and review plugins
 *
 * Creates a decentralized plugin marketplace where agents are both
 * consumers and producers of capabilities.
 */
class MarketplaceDatabase
{
    private \PDO $pdo;

    public function __construct(string $dbPath)
    {
        $this->pdo = new \PDO("sqlite:$dbPath");
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->migrate();
    }

    private function migrate(): void
    {
        // Enable WAL mode for better concurrency
        $this->pdo->exec('PRAGMA journal_mode=WAL');

        // Plugin Offerings - Plugins available in the marketplace
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS plugin_offerings (
                id TEXT PRIMARY KEY,
                plugin_name TEXT NOT NULL UNIQUE,
                version TEXT NOT NULL,
                description TEXT NOT NULL,
                author_agent_id TEXT NOT NULL,
                author_node_id TEXT NOT NULL,
                capabilities TEXT NOT NULL, -- JSON array
                requirements TEXT NOT NULL, -- JSON array
                code_url TEXT, -- Git URL or file path
                code_hash TEXT, -- SHA-256 of plugin code
                downloads INTEGER NOT NULL DEFAULT 0,
                rating_avg REAL NOT NULL DEFAULT 0.0,
                rating_count INTEGER NOT NULL DEFAULT 0,
                featured INTEGER NOT NULL DEFAULT 0,
                verified INTEGER NOT NULL DEFAULT 0, -- Verified by humans/trusted agents
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (author_agent_id) REFERENCES agents(id) ON DELETE SET NULL
            )
        SQL);

        // Plugin Bounties - Requests for plugins
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS plugin_bounties (
                id TEXT PRIMARY KEY,
                plugin_name TEXT NOT NULL,
                description TEXT NOT NULL,
                requester_agent_id TEXT NOT NULL,
                requester_node_id TEXT NOT NULL,
                required_capabilities TEXT NOT NULL, -- JSON array
                reward TEXT, -- Optional: tokens, credits, etc.
                status TEXT NOT NULL DEFAULT 'open', -- open, claimed, completed, cancelled
                claimed_by_agent_id TEXT,
                claimed_at TEXT,
                completed_at TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (requester_agent_id) REFERENCES agents(id) ON DELETE CASCADE,
                FOREIGN KEY (claimed_by_agent_id) REFERENCES agents(id) ON DELETE SET NULL
            )
        SQL);

        // Plugin Installations - Track what agents have installed
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS plugin_installations (
                id TEXT PRIMARY KEY,
                agent_id TEXT NOT NULL,
                plugin_name TEXT NOT NULL,
                version TEXT NOT NULL,
                offering_id TEXT NOT NULL,
                installed_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE(agent_id, plugin_name),
                FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
                FOREIGN KEY (offering_id) REFERENCES plugin_offerings(id) ON DELETE CASCADE
            )
        SQL);

        // Plugin Reviews - Ratings and reviews
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS plugin_reviews (
                id TEXT PRIMARY KEY,
                offering_id TEXT NOT NULL,
                reviewer_agent_id TEXT NOT NULL,
                reviewer_node_id TEXT NOT NULL,
                rating INTEGER NOT NULL CHECK(rating >= 1 AND rating <= 5),
                review_text TEXT,
                helpful_count INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE(offering_id, reviewer_agent_id),
                FOREIGN KEY (offering_id) REFERENCES plugin_offerings(id) ON DELETE CASCADE,
                FOREIGN KEY (reviewer_agent_id) REFERENCES agents(id) ON DELETE CASCADE
            )
        SQL);

        // Indexes
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_offerings_name ON plugin_offerings(plugin_name)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_offerings_author ON plugin_offerings(author_agent_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_offerings_featured ON plugin_offerings(featured DESC, rating_avg DESC)');

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_bounties_status ON plugin_bounties(status)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_bounties_requester ON plugin_bounties(requester_agent_id)');

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_installations_agent ON plugin_installations(agent_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_reviews_offering ON plugin_reviews(offering_id)');
    }

    // === OFFERINGS ===

    public function createOffering(array $data): void
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT INTO plugin_offerings (
                id, plugin_name, version, description, author_agent_id, author_node_id,
                capabilities, requirements, code_url, code_hash, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        SQL);

        $stmt->execute([
            $data['id'],
            $data['plugin_name'],
            $data['version'],
            $data['description'],
            $data['author_agent_id'],
            $data['author_node_id'],
            json_encode($data['capabilities'] ?? []),
            json_encode($data['requirements'] ?? []),
            $data['code_url'] ?? null,
            $data['code_hash'] ?? null,
            $data['created_at'],
            $data['updated_at'],
        ]);
    }

    public function getOffering(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM plugin_offerings WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->hydrateOffering($row) : null;
    }

    public function getAllOfferings(int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM plugin_offerings ORDER BY featured DESC, rating_avg DESC, downloads DESC LIMIT ? OFFSET ?'
        );
        $stmt->execute([$limit, $offset]);
        return array_map(fn($row) => $this->hydrateOffering($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function searchOfferings(string $query): array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            SELECT * FROM plugin_offerings
            WHERE plugin_name LIKE ? OR description LIKE ?
            ORDER BY rating_avg DESC, downloads DESC
        SQL);
        $searchTerm = "%{$query}%";
        $stmt->execute([$searchTerm, $searchTerm]);
        return array_map(fn($row) => $this->hydrateOffering($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function incrementDownloads(string $offeringId): void
    {
        $stmt = $this->pdo->prepare('UPDATE plugin_offerings SET downloads = downloads + 1 WHERE id = ?');
        $stmt->execute([$offeringId]);
    }

    // === BOUNTIES ===

    public function createBounty(array $data): void
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT INTO plugin_bounties (
                id, plugin_name, description, requester_agent_id, requester_node_id,
                required_capabilities, reward, status, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        SQL);

        $stmt->execute([
            $data['id'],
            $data['plugin_name'],
            $data['description'],
            $data['requester_agent_id'],
            $data['requester_node_id'],
            json_encode($data['required_capabilities'] ?? []),
            $data['reward'] ?? null,
            'open',
            $data['created_at'],
            $data['updated_at'],
        ]);
    }

    public function getOpenBounties(): array
    {
        $stmt = $this->pdo->query(
            "SELECT * FROM plugin_bounties WHERE status = 'open' ORDER BY created_at DESC"
        );
        return array_map(fn($row) => $this->hydrateBounty($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function claimBounty(string $bountyId, string $agentId): void
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            UPDATE plugin_bounties
            SET status = 'claimed', claimed_by_agent_id = ?, claimed_at = ?, updated_at = ?
            WHERE id = ? AND status = 'open'
        SQL);
        $now = date('Y-m-d H:i:s');
        $stmt->execute([$agentId, $now, $now, $bountyId]);
    }

    public function completeBounty(string $bountyId): void
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            UPDATE plugin_bounties
            SET status = 'completed', completed_at = ?, updated_at = ?
            WHERE id = ?
        SQL);
        $now = date('Y-m-d H:i:s');
        $stmt->execute([$now, $now, $bountyId]);
    }

    // === INSTALLATIONS ===

    public function installPlugin(string $agentId, string $pluginName, string $version, string $offeringId): void
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT OR REPLACE INTO plugin_installations (
                id, agent_id, plugin_name, version, offering_id, installed_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        SQL);

        $id = bin2hex(random_bytes(16));
        $now = date('Y-m-d H:i:s');
        $stmt->execute([$id, $agentId, $pluginName, $version, $offeringId, $now, $now]);

        // Increment download counter
        $this->incrementDownloads($offeringId);
    }

    public function getAgentInstallations(string $agentId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM plugin_installations WHERE agent_id = ?');
        $stmt->execute([$agentId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // === REVIEWS ===

    public function createReview(array $data): void
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT OR REPLACE INTO plugin_reviews (
                id, offering_id, reviewer_agent_id, reviewer_node_id,
                rating, review_text, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        SQL);

        $stmt->execute([
            $data['id'],
            $data['offering_id'],
            $data['reviewer_agent_id'],
            $data['reviewer_node_id'],
            $data['rating'],
            $data['review_text'] ?? null,
            $data['created_at'],
            $data['updated_at'],
        ]);

        // Recalculate offering rating
        $this->recalculateOfferingRating($data['offering_id']);
    }

    private function recalculateOfferingRating(string $offeringId): void
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            UPDATE plugin_offerings
            SET rating_avg = (SELECT AVG(rating) FROM plugin_reviews WHERE offering_id = ?),
                rating_count = (SELECT COUNT(*) FROM plugin_reviews WHERE offering_id = ?)
            WHERE id = ?
        SQL);
        $stmt->execute([$offeringId, $offeringId, $offeringId]);
    }

    // === HYDRATION ===

    private function hydrateOffering(array $row): array
    {
        $row['capabilities'] = json_decode($row['capabilities'], true);
        $row['requirements'] = json_decode($row['requirements'], true);
        return $row;
    }

    private function hydrateBounty(array $row): array
    {
        $row['required_capabilities'] = json_decode($row['required_capabilities'], true);
        return $row;
    }
}
