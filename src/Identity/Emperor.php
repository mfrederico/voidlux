<?php

declare(strict_types=1);

namespace VoidLux\Identity;

use VoidLux\P2P\Protocol\LamportClock;
use VoidLux\P2P\Transport\TcpMesh;

/**
 * Emperor-specific identity management.
 *
 * Extends the base IdentityManager with emperor responsibilities:
 * - Issues `swarm_member` credentials to workers that join the mesh
 * - Issues `emperor_trust` credentials for leadership delegation
 * - Verifies that peers claiming emperor role hold valid credentials
 * - Manages credential lifecycle (renewal, revocation via expiry)
 *
 * Trust model:
 *   The emperor's DID is the root of trust for the swarm. Workers receive
 *   a `swarm_member` credential signed by the emperor. During leader
 *   election, the new emperor inherits trust by having an existing
 *   `swarm_member` credential — any node in the swarm can verify this.
 */
class Emperor
{
    // Credential types issued by the emperor
    public const CRED_SWARM_MEMBER = 'swarm_member';
    public const CRED_EMPEROR_TRUST = 'emperor_trust';
    public const CRED_AGENT_OPERATOR = 'agent_operator';

    // Default TTLs
    private const MEMBER_TTL = 86400;      // 24h for swarm membership
    private const TRUST_TTL = 43200;       // 12h for emperor trust delegation
    private const OPERATOR_TTL = 86400;    // 24h for agent operator rights

    private IdentityManager $identity;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly TcpMesh $mesh,
        private readonly LamportClock $clock,
        private readonly string $nodeId,
        private readonly string $role,
    ) {}

    /**
     * Bootstrap the emperor identity system.
     * Creates or loads the node's key pair and identity, then announces to the mesh.
     */
    public function boot(): self
    {
        $this->identity = IdentityManager::boot(
            $this->pdo,
            $this->mesh,
            $this->clock,
            $this->nodeId,
            $this->role,
        );

        // Self-issue emperor_trust if we are the emperor
        if ($this->role === 'emperor') {
            $this->selfCertify();
        }

        return $this;
    }

    /**
     * Get the underlying IdentityManager.
     */
    public function identityManager(): IdentityManager
    {
        return $this->identity;
    }

    /**
     * Get this node's DID.
     */
    public function did(): string
    {
        return $this->identity->did();
    }

    /**
     * Announce this node's identity to all connected peers.
     */
    public function announce(): void
    {
        $this->identity->announce();
    }

    /**
     * Issue a swarm membership credential to a peer node.
     * Called when a worker successfully joins the mesh and passes authentication.
     */
    public function admitMember(string $peerDid, array $claims = []): Credential
    {
        $defaultClaims = [
            'admitted_by' => $this->did(),
            'admitted_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        return $this->identity->issueCredential(
            $peerDid,
            self::CRED_SWARM_MEMBER,
            array_merge($defaultClaims, $claims),
            self::MEMBER_TTL,
        );
    }

    /**
     * Issue an agent_operator credential, granting a node the right to run agents.
     */
    public function grantAgentOperator(string $peerDid, int $maxAgents = 10): Credential
    {
        return $this->identity->issueCredential(
            $peerDid,
            self::CRED_AGENT_OPERATOR,
            [
                'max_agents' => $maxAgents,
                'granted_by' => $this->did(),
            ],
            self::OPERATOR_TTL,
        );
    }

    /**
     * Issue emperor_trust to another node (for leadership delegation / failover).
     * This credential allows a node to become emperor during leader election.
     */
    public function delegateTrust(string $peerDid): Credential
    {
        return $this->identity->issueCredential(
            $peerDid,
            self::CRED_EMPEROR_TRUST,
            [
                'delegated_by' => $this->did(),
                'delegated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
            self::TRUST_TTL,
        );
    }

    /**
     * Check if a peer node is an admitted swarm member.
     */
    public function isMember(string $peerDid): bool
    {
        return $this->identity->verifier()->hasCredential($peerDid, self::CRED_SWARM_MEMBER);
    }

    /**
     * Check if a peer node has emperor trust (eligible for election).
     */
    public function hasEmperorTrust(string $peerDid): bool
    {
        return $this->identity->verifier()->hasCredential($peerDid, self::CRED_EMPEROR_TRUST);
    }

    /**
     * Check if a peer node can operate agents.
     */
    public function canOperateAgents(string $peerDid): bool
    {
        return $this->identity->verifier()->hasCredential($peerDid, self::CRED_AGENT_OPERATOR);
    }

    /**
     * Verify a peer's identity via challenge-response.
     * Returns the verification result. The peer must sign the challenge
     * with their Ed25519 private key.
     *
     * @return array{0: string, 1: string} [challenge, nonce]
     */
    public function createChallenge(): array
    {
        return $this->identity->createChallenge();
    }

    /**
     * Sign a challenge sent by a peer (proving we hold our private key).
     */
    public function signChallenge(string $challenge): string
    {
        return $this->identity->signChallenge($challenge);
    }

    /**
     * Verify a challenge response from a peer.
     */
    public function verifyChallenge(string $challenge, string $signatureHex, string $claimedDid): VerificationResult
    {
        return $this->identity->verifyChallenge($challenge, $signatureHex, $claimedDid);
    }

    /**
     * Handle a new peer joining the mesh: verify identity, admit as member, grant operator rights.
     * Called after a peer passes the HELLO handshake + auth challenge.
     *
     * @return array{admitted: bool, credentials: Credential[], reason: string}
     */
    public function onPeerJoined(string $peerNodeId, string $peerRole): array
    {
        $peerDid = "did:voidlux:{$peerNodeId}";
        $credentials = [];

        // Only the emperor issues credentials
        if ($this->role !== 'emperor') {
            return [
                'admitted' => true,
                'credentials' => [],
                'reason' => 'Not emperor — skipping credential issuance',
            ];
        }

        // Check if already a member
        if (!$this->isMember($peerDid)) {
            $credentials[] = $this->admitMember($peerDid, ['role' => $peerRole]);
        }

        // Grant agent operator rights to workers
        if ($peerRole === 'worker' && !$this->canOperateAgents($peerDid)) {
            $credentials[] = $this->grantAgentOperator($peerDid);
        }

        // Delegate emperor trust to workers (for failover eligibility)
        if ($peerRole === 'worker' && !$this->hasEmperorTrust($peerDid)) {
            $credentials[] = $this->delegateTrust($peerDid);
        }

        return [
            'admitted' => true,
            'credentials' => $credentials,
            'reason' => 'Admitted with ' . count($credentials) . ' credential(s)',
        ];
    }

    /**
     * Get all valid credentials for a peer.
     * @return Credential[]
     */
    public function getPeerCredentials(string $peerDid): array
    {
        return $this->identity->verifier()->getValidCredentials($peerDid);
    }

    /**
     * Get identity stats for the dashboard.
     */
    public function getStats(): array
    {
        $store = $this->identity->store();
        $allIdentities = $store->getAllIdentities();
        $memberCreds = $store->getCredentialsByType(self::CRED_SWARM_MEMBER);
        $trustCreds = $store->getCredentialsByType(self::CRED_EMPEROR_TRUST);

        return [
            'did' => $this->did(),
            'role' => $this->role,
            'known_identities' => count($allIdentities),
            'swarm_members' => count($memberCreds),
            'emperor_trust_delegations' => count($trustCreds),
        ];
    }

    /**
     * Prune expired credentials.
     */
    public function pruneExpired(): int
    {
        return $this->identity->pruneExpired();
    }

    /**
     * Self-certify as emperor: issue emperor_trust to ourselves.
     * This is the root of the trust chain.
     */
    private function selfCertify(): void
    {
        $myDid = $this->did();

        if (!$this->hasEmperorTrust($myDid)) {
            $this->identity->issueCredential(
                $myDid,
                self::CRED_EMPEROR_TRUST,
                [
                    'self_certified' => true,
                    'role' => 'emperor',
                ],
                self::TRUST_TTL,
            );
        }

        if (!$this->isMember($myDid)) {
            $this->identity->issueCredential(
                $myDid,
                self::CRED_SWARM_MEMBER,
                [
                    'self_certified' => true,
                    'role' => 'emperor',
                ],
                self::MEMBER_TTL,
            );
        }
    }
}
