<?php

declare(strict_types=1);

namespace VoidLux\Identity;

/**
 * SQLite-backed store for identities and credentials.
 * Follows the SwarmDatabase pattern — local per-node, gossip syncs across peers.
 */
class IdentityStore
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
            CREATE TABLE IF NOT EXISTS identities (
                did TEXT PRIMARY KEY,
                node_id TEXT NOT NULL,
                public_key TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT \'worker\',
                created_at TEXT NOT NULL,
                lamport_ts INTEGER NOT NULL
            )
        ');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_identities_node ON identities(node_id)');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS credentials (
                id TEXT PRIMARY KEY,
                issuer_did TEXT NOT NULL,
                subject_did TEXT NOT NULL,
                type TEXT NOT NULL,
                claims TEXT NOT NULL DEFAULT \'{}\',
                signature TEXT NOT NULL,
                issued_at TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                lamport_ts INTEGER NOT NULL
            )
        ');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_credentials_subject ON credentials(subject_did)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_credentials_issuer ON credentials(issuer_did)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_credentials_type ON credentials(type)');
    }

    // --- Identity operations ---

    public function storeIdentity(DecentralizedIdentity $identity): bool
    {
        $stmt = $this->pdo->prepare('
            INSERT OR REPLACE INTO identities (did, node_id, public_key, role, created_at, lamport_ts)
            VALUES (:did, :node_id, :public_key, :role, :created_at, :lamport_ts)
        ');
        return $stmt->execute([
            ':did' => $identity->did,
            ':node_id' => $identity->nodeId,
            ':public_key' => $identity->publicKeyHex,
            ':role' => $identity->role,
            ':created_at' => $identity->createdAt,
            ':lamport_ts' => $identity->lamportTs,
        ]);
    }

    public function getIdentity(string $did): ?DecentralizedIdentity
    {
        $stmt = $this->pdo->prepare('SELECT * FROM identities WHERE did = :did');
        $stmt->execute([':did' => $did]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? DecentralizedIdentity::fromArray($row) : null;
    }

    public function getIdentityByNodeId(string $nodeId): ?DecentralizedIdentity
    {
        $stmt = $this->pdo->prepare('SELECT * FROM identities WHERE node_id = :node_id LIMIT 1');
        $stmt->execute([':node_id' => $nodeId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? DecentralizedIdentity::fromArray($row) : null;
    }

    /** @return DecentralizedIdentity[] */
    public function getAllIdentities(): array
    {
        $rows = $this->pdo->query('SELECT * FROM identities ORDER BY created_at ASC')->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn(array $row) => DecentralizedIdentity::fromArray($row), $rows);
    }

    /** @return DecentralizedIdentity[] */
    public function getIdentitiesSince(int $lamportTs): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM identities WHERE lamport_ts > :ts ORDER BY lamport_ts ASC');
        $stmt->execute([':ts' => $lamportTs]);
        return array_map(fn(array $row) => DecentralizedIdentity::fromArray($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function getMaxIdentityLamportTs(): int
    {
        return (int) $this->pdo->query('SELECT COALESCE(MAX(lamport_ts), 0) FROM identities')->fetchColumn();
    }

    // --- Credential operations ---

    public function storeCredential(Credential $credential): bool
    {
        $stmt = $this->pdo->prepare('
            INSERT OR REPLACE INTO credentials
                (id, issuer_did, subject_did, type, claims, signature, issued_at, expires_at, lamport_ts)
            VALUES
                (:id, :issuer_did, :subject_did, :type, :claims, :signature, :issued_at, :expires_at, :lamport_ts)
        ');
        return $stmt->execute([
            ':id' => $credential->id,
            ':issuer_did' => $credential->issuerDid,
            ':subject_did' => $credential->subjectDid,
            ':type' => $credential->type,
            ':claims' => json_encode($credential->claims),
            ':signature' => $credential->signatureHex,
            ':issued_at' => $credential->issuedAt,
            ':expires_at' => $credential->expiresAt,
            ':lamport_ts' => $credential->lamportTs,
        ]);
    }

    public function getCredential(string $id): ?Credential
    {
        $stmt = $this->pdo->prepare('SELECT * FROM credentials WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? Credential::fromArray($row) : null;
    }

    /** @return Credential[] */
    public function getCredentialsForSubject(string $subjectDid): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM credentials WHERE subject_did = :did ORDER BY issued_at DESC');
        $stmt->execute([':did' => $subjectDid]);
        return array_map(fn(array $row) => Credential::fromArray($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return Credential[] */
    public function getCredentialsByType(string $type): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM credentials WHERE type = :type ORDER BY issued_at DESC');
        $stmt->execute([':type' => $type]);
        return array_map(fn(array $row) => Credential::fromArray($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return Credential[] */
    public function getCredentialsSince(int $lamportTs): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM credentials WHERE lamport_ts > :ts ORDER BY lamport_ts ASC');
        $stmt->execute([':ts' => $lamportTs]);
        return array_map(fn(array $row) => Credential::fromArray($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function getMaxCredentialLamportTs(): int
    {
        return (int) $this->pdo->query('SELECT COALESCE(MAX(lamport_ts), 0) FROM credentials')->fetchColumn();
    }

    /**
     * Remove expired credentials.
     * @return int Number of credentials removed
     */
    public function pruneExpired(): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('DELETE FROM credentials WHERE expires_at < :now');
        $stmt->execute([':now' => $now]);
        return $stmt->rowCount();
    }
}
