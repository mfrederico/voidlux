<?php

declare(strict_types=1);

namespace VoidLux\Identity;

use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\Connection;
use VoidLux\P2P\Transport\TcpMesh;

/**
 * Ed25519 identity-based authentication for P2P connections.
 *
 * Bridges the Identity system (Ed25519 key pairs, DIDs) with the P2P
 * connection layer. Provides cryptographic proof of identity during
 * the HELLO handshake using challenge-response with digital signatures.
 *
 * Protocol flow (after HELLO + optional HMAC auth):
 *   1. Receiver sends IDENTITY_CHALLENGE with random nonce
 *   2. Initiator signs (nonce + node_id + timestamp) with Ed25519 private key
 *   3. Receiver verifies signature against initiator's known public key
 *   4. Connection is marked as identity-verified
 *
 * This is stronger than HMAC shared-secret auth because:
 * - Each node has a unique key pair (no shared secret to distribute)
 * - Signatures prove the exact identity, not just "knows the password"
 * - Works across network boundaries without pre-shared secrets
 * - Integrates with the DID/credential system for authorization
 *
 * Usage:
 *   $auth = new Authentication($emperor, $mesh, $nodeId);
 *   $auth->onIdentityVerified(function (Connection $conn, string $peerDid) { ... });
 *   $auth->onIdentityRejected(function (Connection $conn, string $reason) { ... });
 */
class Authentication
{
    /** @var array<string, string> connection address => challenge nonce */
    private array $pendingChallenges = [];

    /** @var array<string, float> connection address => challenge timestamp */
    private array $challengeTimestamps = [];

    /** @var array<string, bool> connection address => identity verified */
    private array $verified = [];

    /** @var array<string, string> connection address => verified DID */
    private array $verifiedDids = [];

    private const CHALLENGE_TTL = 30.0; // 30 seconds
    private const CHALLENGE_PREFIX = 'voidlux-identity-v1';

    /** @var callable(Connection, string, string): void */
    private $onVerified;

    /** @var callable(Connection, string): void */
    private $onRejected;

    /** @var callable(string): void */
    private $logger;

    public function __construct(
        private readonly Emperor $emperor,
        private readonly TcpMesh $mesh,
        private readonly string $nodeId,
    ) {}

    /**
     * Callback when a peer's identity is cryptographically verified.
     * @param callable(Connection, string $peerDid, string $peerNodeId): void $cb
     */
    public function onIdentityVerified(callable $cb): void
    {
        $this->onVerified = $cb;
    }

    /**
     * Callback when identity verification fails.
     * @param callable(Connection, string $reason): void $cb
     */
    public function onIdentityRejected(callable $cb): void
    {
        $this->onRejected = $cb;
    }

    public function onLog(callable $cb): void
    {
        $this->logger = $cb;
    }

    /**
     * Issue an identity challenge to a peer after receiving their HELLO.
     * Call this from the connection protocol's onAuthenticated callback.
     */
    public function challengePeer(Connection $conn, string $peerNodeId): void
    {
        $nonce = bin2hex(random_bytes(16));
        $timestamp = time();
        $challenge = self::CHALLENGE_PREFIX . ":{$nonce}:{$timestamp}";

        $address = $conn->address();
        $this->pendingChallenges[$address] = $challenge;
        $this->challengeTimestamps[$address] = microtime(true);

        $conn->send([
            'type' => MessageTypes::IDENTITY_ANNOUNCE,
            'action' => 'challenge',
            'challenge' => $challenge,
            'challenger_did' => $this->emperor->did(),
        ]);

        $this->log("Sent identity challenge to {$peerNodeId} at {$address}");
    }

    /**
     * Handle an incoming identity-related message.
     * Dispatches to challenge/response/verify handlers.
     */
    public function handleMessage(Connection $conn, array $msg): void
    {
        $action = $msg['action'] ?? '';

        switch ($action) {
            case 'challenge':
                $this->handleChallenge($conn, $msg);
                break;

            case 'response':
                $this->handleResponse($conn, $msg);
                break;
        }
    }

    /**
     * Handle an identity challenge from a peer: sign it and send back.
     */
    private function handleChallenge(Connection $conn, array $msg): void
    {
        $challenge = $msg['challenge'] ?? '';
        if ($challenge === '') {
            $this->log("Empty challenge from {$conn->address()}");
            return;
        }

        // Sign the challenge with our Ed25519 private key
        $signatureHex = $this->emperor->signChallenge($challenge);

        $conn->send([
            'type' => MessageTypes::IDENTITY_ANNOUNCE,
            'action' => 'response',
            'challenge' => $challenge,
            'signature' => $signatureHex,
            'did' => $this->emperor->did(),
            'node_id' => $this->nodeId,
        ]);

        $this->log("Signed and sent identity response to {$conn->address()}");
    }

    /**
     * Handle an identity challenge response: verify the signature.
     */
    private function handleResponse(Connection $conn, array $msg): void
    {
        $address = $conn->address();
        $challenge = $msg['challenge'] ?? '';
        $signatureHex = $msg['signature'] ?? '';
        $claimedDid = $msg['did'] ?? '';
        $peerNodeId = $msg['node_id'] ?? '';

        // Verify we have a pending challenge for this connection
        $pendingChallenge = $this->pendingChallenges[$address] ?? null;
        if ($pendingChallenge === null) {
            $this->log("No pending challenge for {$address}");
            return;
        }

        // Verify the challenge matches
        if ($challenge !== $pendingChallenge) {
            $this->reject($conn, 'Challenge mismatch');
            return;
        }

        // Check challenge freshness
        $elapsed = microtime(true) - ($this->challengeTimestamps[$address] ?? 0);
        if ($elapsed > self::CHALLENGE_TTL) {
            $this->reject($conn, 'Challenge expired');
            return;
        }

        // Verify the Ed25519 signature
        $result = $this->emperor->verifyChallenge($challenge, $signatureHex, $claimedDid);

        // Clean up pending state
        unset($this->pendingChallenges[$address]);
        unset($this->challengeTimestamps[$address]);

        if ($result->valid) {
            $this->verified[$address] = true;
            $this->verifiedDids[$address] = $claimedDid;

            $this->log("Identity verified: {$claimedDid} ({$peerNodeId}) at {$address}");

            if ($this->onVerified) {
                ($this->onVerified)($conn, $claimedDid, $peerNodeId);
            }
        } else {
            $this->reject($conn, "Signature verification failed: {$result->reason}");
        }
    }

    /**
     * Check if a connection has been identity-verified.
     */
    public function isVerified(Connection $conn): bool
    {
        return $this->verified[$conn->address()] ?? false;
    }

    /**
     * Get the verified DID for a connection.
     */
    public function getVerifiedDid(Connection $conn): ?string
    {
        return $this->verifiedDids[$conn->address()] ?? null;
    }

    /**
     * Clean up state when a peer disconnects.
     */
    public function onDisconnect(Connection $conn): void
    {
        $address = $conn->address();
        unset($this->pendingChallenges[$address]);
        unset($this->challengeTimestamps[$address]);
        unset($this->verified[$address]);
        unset($this->verifiedDids[$address]);
    }

    /**
     * Prune expired challenges.
     * @return int Number of challenges pruned
     */
    public function pruneExpiredChallenges(): int
    {
        $now = microtime(true);
        $pruned = 0;

        foreach ($this->challengeTimestamps as $address => $timestamp) {
            if ($now - $timestamp > self::CHALLENGE_TTL) {
                unset($this->pendingChallenges[$address]);
                unset($this->challengeTimestamps[$address]);
                $pruned++;
            }
        }

        return $pruned;
    }

    /**
     * Verify a peer's identity inline (blocking coroutine-style).
     * Used for one-off verification outside the message handler flow.
     */
    public function verifyPeerIdentity(string $peerNodeId, string $challenge, string $signatureHex): VerificationResult
    {
        $peerDid = "did:voidlux:{$peerNodeId}";
        return $this->emperor->verifyChallenge($challenge, $signatureHex, $peerDid);
    }

    /**
     * Get authentication stats for monitoring.
     */
    public function getStats(): array
    {
        return [
            'verified_connections' => count(array_filter($this->verified)),
            'pending_challenges' => count($this->pendingChallenges),
            'identity_stats' => $this->emperor->getStats(),
        ];
    }

    private function reject(Connection $conn, string $reason): void
    {
        $address = $conn->address();
        unset($this->pendingChallenges[$address]);
        unset($this->challengeTimestamps[$address]);

        $this->log("Identity rejected for {$address}: {$reason}");

        if ($this->onRejected) {
            ($this->onRejected)($conn, $reason);
        }
    }

    private function log(string $message): void
    {
        if ($this->logger) {
            ($this->logger)("[identity-auth] {$message}");
        }
    }
}
