<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Auth;

use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\Connection;

/**
 * HMAC-SHA256 challenge-response authentication for P2P connections.
 *
 * Protocol flow:
 *   1. Initiator sends HELLO (with node_id, role)
 *   2. Receiver replies with AUTH_CHALLENGE (random nonce)
 *   3. Initiator computes HMAC-SHA256(secret, nonce + node_id) and sends AUTH_RESPONSE
 *   4. Receiver verifies HMAC — accepts or rejects
 *
 * When no secret is configured, authentication is disabled (open mesh).
 * Emperor role claims require additional verification: the HMAC includes "emperor"
 * so a worker cannot forge emperor HELLO messages.
 */
class ConnectionAuth
{
    private const NONCE_BYTES = 32;
    private const ALGO = 'sha256';

    /** @var array<string, PendingAuth> connection address => pending challenge */
    private array $pending = [];

    public function __construct(
        private readonly string $secret = '',
    ) {}

    /**
     * Whether authentication is enabled (secret configured).
     */
    public function isEnabled(): bool
    {
        return $this->secret !== '';
    }

    /**
     * Generate a challenge for an incoming HELLO.
     * Called by the receiver after receiving HELLO.
     *
     * @return array The AUTH_CHALLENGE message to send back
     */
    public function createChallenge(Connection $conn, string $peerNodeId, string $peerRole): array
    {
        $nonce = bin2hex(random_bytes(self::NONCE_BYTES));

        $this->pending[$conn->address()] = new PendingAuth(
            nonce: $nonce,
            peerNodeId: $peerNodeId,
            peerRole: $peerRole,
            createdAt: microtime(true),
        );

        return [
            'type' => MessageTypes::AUTH_CHALLENGE,
            'nonce' => $nonce,
        ];
    }

    /**
     * Compute the HMAC response to a challenge.
     * Called by the initiator after receiving AUTH_CHALLENGE.
     *
     * @return array The AUTH_RESPONSE message to send back
     */
    public function computeResponse(string $nonce, string $nodeId, string $role): array
    {
        $payload = $this->buildHmacPayload($nonce, $nodeId, $role);
        $hmac = hash_hmac(self::ALGO, $payload, $this->secret);

        return [
            'type' => MessageTypes::AUTH_RESPONSE,
            'hmac' => $hmac,
            'node_id' => $nodeId,
            'role' => $role,
        ];
    }

    /**
     * Verify an AUTH_RESPONSE against the pending challenge.
     * Called by the receiver after receiving AUTH_RESPONSE.
     *
     * @return AuthResult
     */
    public function verifyResponse(Connection $conn, array $msg): AuthResult
    {
        $address = $conn->address();
        $pending = $this->pending[$address] ?? null;

        if ($pending === null) {
            return new AuthResult(false, 'No pending challenge for this connection');
        }

        // Expire stale challenges (30s)
        if (microtime(true) - $pending->createdAt > 30.0) {
            unset($this->pending[$address]);
            return new AuthResult(false, 'Challenge expired');
        }

        $peerHmac = $msg['hmac'] ?? '';
        $nodeId = $msg['node_id'] ?? '';
        $role = $msg['role'] ?? '';

        // Verify the claimed identity matches the HELLO
        if ($nodeId !== $pending->peerNodeId) {
            unset($this->pending[$address]);
            return new AuthResult(false, 'Node ID mismatch');
        }

        if ($role !== $pending->peerRole) {
            unset($this->pending[$address]);
            return new AuthResult(false, 'Role mismatch');
        }

        // Compute expected HMAC
        $payload = $this->buildHmacPayload($pending->nonce, $nodeId, $role);
        $expected = hash_hmac(self::ALGO, $payload, $this->secret);

        // Constant-time comparison
        if (!hash_equals($expected, $peerHmac)) {
            unset($this->pending[$address]);
            return new AuthResult(false, 'HMAC verification failed');
        }

        unset($this->pending[$address]);
        return new AuthResult(true, 'Authenticated');
    }

    /**
     * Cancel a pending challenge (e.g., on disconnect).
     */
    public function cancelPending(Connection $conn): void
    {
        unset($this->pending[$conn->address()]);
    }

    /**
     * Prune expired challenges.
     */
    public function pruneExpired(): int
    {
        $now = microtime(true);
        $pruned = 0;

        foreach ($this->pending as $address => $pending) {
            if ($now - $pending->createdAt > 30.0) {
                unset($this->pending[$address]);
                $pruned++;
            }
        }

        return $pruned;
    }

    /**
     * Build the HMAC payload string.
     * Includes role so emperor/worker claims are cryptographically bound.
     */
    private function buildHmacPayload(string $nonce, string $nodeId, string $role): string
    {
        return "voidlux:auth:v1:{$nonce}:{$nodeId}:{$role}";
    }
}
