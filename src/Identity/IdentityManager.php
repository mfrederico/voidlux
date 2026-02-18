<?php

declare(strict_types=1);

namespace VoidLux\Identity;

use VoidLux\P2P\Protocol\LamportClock;
use VoidLux\P2P\Transport\TcpMesh;

/**
 * Main entry point for the decentralized identity system.
 *
 * Manages the lifecycle of node identities, credentials, and verification
 * in a fully decentralized manner — no central authority required.
 *
 * Usage:
 *   $mgr = IdentityManager::boot($pdo, $mesh, $clock, $nodeId, 'emperor');
 *   // Identity is auto-generated on first boot, persisted in SQLite
 *
 *   // Issue a credential to another node
 *   $cred = $mgr->issueCredential($peerDid, 'swarm_member', ['trusted' => true]);
 *
 *   // Verify a peer via challenge-response
 *   [$challenge, $nonce] = $mgr->createChallenge();
 *   // send challenge to peer, get signature back...
 *   $result = $mgr->verifyChallenge($challenge, $signatureHex, $peerDid);
 *
 *   // Check a node's credentials
 *   $isMember = $mgr->verifier()->hasCredential($peerDid, 'swarm_member');
 */
class IdentityManager
{
    private DecentralizedIdentity $identity;

    public function __construct(
        private readonly IdentityKeyPair $keyPair,
        private readonly IdentityStore $store,
        private readonly IdentityGossip $gossip,
        private readonly IdentityVerifier $verifier,
        private readonly LamportClock $clock,
        private readonly string $nodeId,
    ) {}

    /**
     * Bootstrap the identity system for a node.
     * Generates a key pair on first boot; loads from SQLite on subsequent boots.
     */
    public static function boot(
        \PDO $pdo,
        TcpMesh $mesh,
        LamportClock $clock,
        string $nodeId,
        string $role = 'worker',
    ): self {
        $store = new IdentityStore($pdo);
        $gossip = new IdentityGossip($mesh, $store, $clock);
        $verifier = new IdentityVerifier($store);

        // Check for existing key pair in swarm_state
        $stmt = $pdo->prepare("SELECT value FROM swarm_state WHERE key = 'identity_secret_key'");
        $stmt->execute();
        $secretHex = $stmt->fetchColumn();

        if ($secretHex !== false && $secretHex !== '') {
            $keyPair = IdentityKeyPair::fromHex($secretHex);
        } else {
            // First boot: generate key pair and persist
            $keyPair = IdentityKeyPair::generate();
            $pdo->prepare("INSERT OR REPLACE INTO swarm_state (key, value) VALUES ('identity_secret_key', :val)")
                ->execute([':val' => $keyPair->secretKeyHex()]);
        }

        $mgr = new self($keyPair, $store, $gossip, $verifier, $clock, $nodeId);

        // Register our own identity
        $ts = $clock->tick();
        $identity = DecentralizedIdentity::create($nodeId, $keyPair->publicKeyHex(), $role, $ts);
        $store->storeIdentity($identity);
        $mgr->identity = $identity;

        return $mgr;
    }

    /**
     * Get this node's DID.
     */
    public function did(): string
    {
        return $this->identity->did;
    }

    /**
     * Get this node's identity.
     */
    public function identity(): DecentralizedIdentity
    {
        return $this->identity;
    }

    /**
     * Get the verifier for checking credentials and challenges.
     */
    public function verifier(): IdentityVerifier
    {
        return $this->verifier;
    }

    /**
     * Get the gossip engine for wiring into the P2P message handler.
     */
    public function gossip(): IdentityGossip
    {
        return $this->gossip;
    }

    /**
     * Get the identity store.
     */
    public function store(): IdentityStore
    {
        return $this->store;
    }

    /**
     * Announce this node's identity to all connected peers.
     */
    public function announce(): void
    {
        $this->gossip->gossipIdentity($this->identity);
    }

    /**
     * Issue a signed credential about another node.
     */
    public function issueCredential(
        string $subjectDid,
        string $type,
        array $claims = [],
        int $ttlSeconds = 86400,
    ): Credential {
        $ts = $this->clock->tick();
        $credential = Credential::issue(
            $this->keyPair,
            $this->identity->did,
            $subjectDid,
            $type,
            $claims,
            $ts,
            $ttlSeconds,
        );

        $this->store->storeCredential($credential);
        $this->gossip->gossipCredential($credential);

        return $credential;
    }

    /**
     * Sign a challenge for proving our identity to a peer.
     */
    public function signChallenge(string $challenge): string
    {
        return sodium_bin2hex($this->keyPair->sign($challenge));
    }

    /**
     * Create a challenge for a peer (delegates to verifier).
     * @return array{0: string, 1: string} [challenge, nonce]
     */
    public function createChallenge(): array
    {
        return $this->verifier->createChallenge();
    }

    /**
     * Verify a peer's challenge response (delegates to verifier).
     */
    public function verifyChallenge(string $challenge, string $signatureHex, string $claimedDid): VerificationResult
    {
        return $this->verifier->verifyChallenge($challenge, $signatureHex, $claimedDid);
    }

    /**
     * Authenticate a peer node during the HELLO handshake.
     * Returns true if the peer's identity is known and the signature is valid.
     */
    public function authenticatePeer(string $nodeId, string $challenge, string $signatureHex): VerificationResult
    {
        $did = "did:voidlux:{$nodeId}";
        return $this->verifyChallenge($challenge, $signatureHex, $did);
    }

    /**
     * Prune expired credentials from the store.
     */
    public function pruneExpired(): int
    {
        return $this->store->pruneExpired();
    }
}
