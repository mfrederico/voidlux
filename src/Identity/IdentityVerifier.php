<?php

declare(strict_types=1);

namespace VoidLux\Identity;

/**
 * Verifies node identities and credentials without any central authority.
 *
 * Trust model:
 * - Every node's DID is bound to an Ed25519 public key
 * - Credentials are signed by the issuer's key — anyone with the public key can verify
 * - Identities propagate via gossip; verification is purely local
 * - Challenge-response proves a peer actually holds the private key
 */
class IdentityVerifier
{
    public function __construct(
        private readonly IdentityStore $store,
    ) {}

    /**
     * Verify that a credential is valid:
     *   1. Issuer identity is known
     *   2. Signature is valid against issuer's public key
     *   3. Credential is not expired
     */
    public function verifyCredential(Credential $credential): VerificationResult
    {
        $issuer = $this->store->getIdentity($credential->issuerDid);
        if ($issuer === null) {
            return new VerificationResult(false, 'Unknown issuer: ' . $credential->issuerDid);
        }

        if ($credential->isExpired()) {
            return new VerificationResult(false, 'Credential expired at ' . $credential->expiresAt);
        }

        if (!$credential->verify($issuer->publicKey())) {
            return new VerificationResult(false, 'Invalid signature');
        }

        return new VerificationResult(true, 'Valid credential from ' . $credential->issuerDid);
    }

    /**
     * Generate a challenge for a peer to prove they control their DID's private key.
     * Returns [challenge_bytes, nonce] — send challenge to peer, they must sign it.
     */
    public function createChallenge(): array
    {
        $nonce = bin2hex(random_bytes(16));
        $challenge = "voidlux-auth:{$nonce}:" . time();
        return [$challenge, $nonce];
    }

    /**
     * Verify a challenge response: the peer signed our challenge with their key.
     */
    public function verifyChallenge(string $challenge, string $signatureHex, string $claimedDid): VerificationResult
    {
        $identity = $this->store->getIdentity($claimedDid);
        if ($identity === null) {
            return new VerificationResult(false, 'Unknown DID: ' . $claimedDid);
        }

        // Check challenge freshness (5 minute window)
        $parts = explode(':', $challenge);
        $timestamp = (int) ($parts[2] ?? 0);
        if (abs(time() - $timestamp) > 300) {
            return new VerificationResult(false, 'Challenge expired');
        }

        try {
            $signature = sodium_hex2bin($signatureHex);
        } catch (\SodiumException) {
            return new VerificationResult(false, 'Invalid signature encoding');
        }

        if (!IdentityKeyPair::verify($challenge, $signature, $identity->publicKey())) {
            return new VerificationResult(false, 'Challenge signature invalid');
        }

        return new VerificationResult(true, 'Authenticated: ' . $claimedDid);
    }

    /**
     * Check if a node has a specific credential type (e.g., 'swarm_member', 'emperor_trust').
     */
    public function hasCredential(string $subjectDid, string $type): bool
    {
        $credentials = $this->store->getCredentialsForSubject($subjectDid);
        foreach ($credentials as $credential) {
            if ($credential->type !== $type) {
                continue;
            }
            $result = $this->verifyCredential($credential);
            if ($result->valid && !$credential->isExpired()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get all valid credentials for a subject, filtering out expired and invalid ones.
     * @return Credential[]
     */
    public function getValidCredentials(string $subjectDid): array
    {
        $credentials = $this->store->getCredentialsForSubject($subjectDid);
        $valid = [];
        foreach ($credentials as $credential) {
            $result = $this->verifyCredential($credential);
            if ($result->valid) {
                $valid[] = $credential;
            }
        }
        return $valid;
    }
}
