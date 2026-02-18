<?php

declare(strict_types=1);

namespace VoidLux\Identity;

use VoidLux\P2P\Protocol\LamportClock;
use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\TcpMesh;

/**
 * Gossip-based propagation of identities and credentials across the P2P mesh.
 *
 * Follows the same pattern as TaskGossipEngine:
 * - Push new identities/credentials to all peers on creation
 * - Anti-entropy pull-based sync for missed items
 * - Dedup by ID to prevent broadcast storms
 */
class IdentityGossip
{
    /** @var array<string, true> */
    private array $seenIdentities = [];
    /** @var array<string, true> */
    private array $seenCredentials = [];
    private int $seenLimit = 5000;

    public function __construct(
        private readonly TcpMesh $mesh,
        private readonly IdentityStore $store,
        private readonly LamportClock $clock,
    ) {}

    // --- Identity gossip ---

    /**
     * Broadcast a new/updated identity to all peers.
     */
    public function gossipIdentity(DecentralizedIdentity $identity, ?string $excludeAddress = null): void
    {
        $this->seenIdentities[$identity->did] = true;
        $this->mesh->broadcast([
            'type' => MessageTypes::IDENTITY_ANNOUNCE,
            'identity' => $identity->toArray(),
        ], $excludeAddress);
    }

    /**
     * Handle an identity announcement from a peer.
     * Returns the identity if it was new/updated, null if duplicate.
     */
    public function receiveIdentity(array $data, ?string $senderAddress = null): ?DecentralizedIdentity
    {
        $identityData = $data['identity'] ?? [];
        $did = $identityData['did'] ?? '';

        if (!$did || isset($this->seenIdentities[$did])) {
            return null;
        }

        $existing = $this->store->getIdentity($did);
        $incomingTs = (int) ($identityData['lamport_ts'] ?? 0);

        // Only accept if newer (higher Lamport timestamp)
        if ($existing !== null && $existing->lamportTs >= $incomingTs) {
            $this->seenIdentities[$did] = true;
            return null;
        }

        $this->clock->witness($incomingTs);

        $identity = DecentralizedIdentity::fromArray($identityData);
        $this->store->storeIdentity($identity);
        $this->seenIdentities[$did] = true;

        // Forward to all peers except sender
        $this->gossipIdentity($identity, $senderAddress);
        $this->pruneSeenIdentities();

        return $identity;
    }

    // --- Credential gossip ---

    /**
     * Broadcast a new credential to all peers.
     */
    public function gossipCredential(Credential $credential, ?string $excludeAddress = null): void
    {
        $this->seenCredentials[$credential->id] = true;
        $this->mesh->broadcast([
            'type' => MessageTypes::CREDENTIAL_ISSUE,
            'credential' => $credential->toArray(),
        ], $excludeAddress);
    }

    /**
     * Handle a credential from a peer. Returns the credential if new, null if duplicate.
     */
    public function receiveCredential(array $data, ?string $senderAddress = null): ?Credential
    {
        $credData = $data['credential'] ?? [];
        $id = $credData['id'] ?? '';

        if (!$id || isset($this->seenCredentials[$id])) {
            return null;
        }

        $existing = $this->store->getCredential($id);
        if ($existing !== null) {
            $this->seenCredentials[$id] = true;
            return null;
        }

        $incomingTs = (int) ($credData['lamport_ts'] ?? 0);
        $this->clock->witness($incomingTs);

        $credential = Credential::fromArray($credData);

        // Verify signature before storing (requires issuer's public key to be known)
        $issuer = $this->store->getIdentity($credential->issuerDid);
        if ($issuer !== null && !$credential->verify($issuer->publicKey())) {
            // Invalid signature — do not store or forward
            return null;
        }

        $this->store->storeCredential($credential);
        $this->seenCredentials[$id] = true;

        // Forward to all peers except sender
        $this->gossipCredential($credential, $senderAddress);
        $this->pruneSeenCredentials();

        return $credential;
    }

    // --- Anti-entropy sync ---

    /**
     * Handle a sync request: send identities and credentials newer than the given timestamps.
     */
    public function handleSyncRequest(\VoidLux\P2P\Transport\Connection $conn, array $msg): void
    {
        $identitySince = (int) ($msg['identity_ts'] ?? 0);
        $credentialSince = (int) ($msg['credential_ts'] ?? 0);

        $identities = $this->store->getIdentitiesSince($identitySince);
        $credentials = $this->store->getCredentialsSince($credentialSince);

        $conn->send([
            'type' => MessageTypes::IDENTITY_SYNC_RSP,
            'identities' => array_map(fn($i) => $i->toArray(), $identities),
            'credentials' => array_map(fn($c) => $c->toArray(), $credentials),
        ]);
    }

    /**
     * Handle a sync response: store new identities and credentials.
     * @return int Number of new items stored
     */
    public function handleSyncResponse(array $msg): int
    {
        $count = 0;

        foreach ($msg['identities'] ?? [] as $data) {
            $did = $data['did'] ?? '';
            $existing = $this->store->getIdentity($did);
            $incomingTs = (int) ($data['lamport_ts'] ?? 0);
            if ($existing === null || $existing->lamportTs < $incomingTs) {
                $this->store->storeIdentity(DecentralizedIdentity::fromArray($data));
                $this->seenIdentities[$did] = true;
                $count++;
            }
        }

        foreach ($msg['credentials'] ?? [] as $data) {
            $id = $data['id'] ?? '';
            if (!$this->store->getCredential($id)) {
                $credential = Credential::fromArray($data);
                // Verify before storing
                $issuer = $this->store->getIdentity($credential->issuerDid);
                if ($issuer === null || $credential->verify($issuer->publicKey())) {
                    $this->store->storeCredential($credential);
                    $this->seenCredentials[$id] = true;
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Request identity/credential sync from a peer.
     */
    public function syncFromPeer(\VoidLux\P2P\Transport\Connection $conn): void
    {
        $conn->send([
            'type' => MessageTypes::IDENTITY_SYNC_REQ,
            'identity_ts' => $this->store->getMaxIdentityLamportTs(),
            'credential_ts' => $this->store->getMaxCredentialLamportTs(),
        ]);
    }

    private function pruneSeenIdentities(): void
    {
        if (count($this->seenIdentities) > $this->seenLimit) {
            $this->seenIdentities = array_slice($this->seenIdentities, -($this->seenLimit / 2), null, true);
        }
    }

    private function pruneSeenCredentials(): void
    {
        if (count($this->seenCredentials) > $this->seenLimit) {
            $this->seenCredentials = array_slice($this->seenCredentials, -($this->seenLimit / 2), null, true);
        }
    }
}
