<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Auth;

use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\Connection;
use VoidLux\P2P\Transport\TcpMesh;

/**
 * Secure connection protocol for emperor systems in the decentralized VoidLux network.
 *
 * Wraps the P2P mesh with authentication and role verification:
 *
 * 1. HELLO handshake establishes identity (node_id, role, ports)
 * 2. AUTH_CHALLENGE/AUTH_RESPONSE performs HMAC-SHA256 mutual authentication
 * 3. Connections track authenticated state — unauthenticated peers are rejected
 * 4. Emperor role claims are cryptographically bound into the HMAC
 *
 * Integrates with the existing TcpMesh by intercepting onConnection/onMessage
 * callbacks and inserting the auth layer before passing messages to the swarm.
 *
 * Usage:
 *   $protocol = new EmperorConnectionProtocol($mesh, $nodeId, $role, $secret);
 *   $protocol->onAuthenticated(function (Connection $conn, string $peerNodeId, string $peerRole) { ... });
 *   $protocol->onMessage(function (Connection $conn, array $msg) { ... });
 *   $protocol->onRejected(function (Connection $conn, string $reason) { ... });
 *   $protocol->install();
 */
class EmperorConnectionProtocol
{
    private ConnectionAuth $auth;

    /** @var array<string, bool> address => authenticated */
    private array $authenticated = [];

    /** @var array<string, string> address => peer node_id */
    private array $peerNodeIds = [];

    /** @var array<string, string> address => peer role */
    private array $peerRoles = [];

    /** @var callable(Connection, string, string): void */
    private $onAuthenticated;

    /** @var callable(Connection, array): void */
    private $onMessage;

    /** @var callable(Connection): void */
    private $onDisconnect;

    /** @var callable(Connection, string): void */
    private $onRejected;

    /** @var callable(string): void */
    private $logger;

    public function __construct(
        private readonly TcpMesh $mesh,
        private readonly string $nodeId,
        private readonly string $role,
        private readonly int $httpPort,
        private readonly int $p2pPort,
        string $secret = '',
    ) {
        $this->auth = new ConnectionAuth($secret);
    }

    public function onAuthenticated(callable $cb): void
    {
        $this->onAuthenticated = $cb;
    }

    public function onMessage(callable $cb): void
    {
        $this->onMessage = $cb;
    }

    public function onDisconnect(callable $cb): void
    {
        $this->onDisconnect = $cb;
    }

    public function onRejected(callable $cb): void
    {
        $this->onRejected = $cb;
    }

    public function onLog(callable $cb): void
    {
        $this->logger = $cb;
    }

    /**
     * Whether authentication is enabled.
     */
    public function isAuthEnabled(): bool
    {
        return $this->auth->isEnabled();
    }

    /**
     * Check if a connection is authenticated.
     */
    public function isAuthenticated(Connection $conn): bool
    {
        if (!$this->auth->isEnabled()) {
            return true; // Auth disabled = all connections trusted
        }
        return $this->authenticated[$conn->address()] ?? false;
    }

    /**
     * Get the authenticated role for a peer connection.
     */
    public function getPeerRole(Connection $conn): ?string
    {
        return $this->peerRoles[$conn->address()] ?? null;
    }

    /**
     * Get the authenticated node ID for a peer connection.
     */
    public function getPeerNodeId(Connection $conn): ?string
    {
        return $this->peerNodeIds[$conn->address()] ?? null;
    }

    /**
     * Install the protocol as the mesh's connection/message/disconnect handler.
     * This intercepts all callbacks and injects the auth layer.
     */
    public function install(): void
    {
        $this->mesh->onConnection(function (Connection $conn) {
            $this->handleNewConnection($conn);
        });

        $this->mesh->onMessage(function (Connection $conn, array $msg) {
            $this->handleMessage($conn, $msg);
        });

        $this->mesh->onDisconnect(function (Connection $conn) {
            $this->handleDisconnect($conn);
        });
    }

    /**
     * Handle a new outbound or inbound connection.
     * Send HELLO immediately (same as existing behavior).
     */
    private function handleNewConnection(Connection $conn): void
    {
        // Send HELLO with our identity
        $conn->send([
            'type' => MessageTypes::HELLO,
            'node_id' => $this->nodeId,
            'p2p_port' => $this->p2pPort,
            'http_port' => $this->httpPort,
            'role' => $this->role,
        ]);
    }

    /**
     * Handle an incoming message, applying auth logic.
     */
    private function handleMessage(Connection $conn, array $msg): void
    {
        $type = $msg['type'] ?? 0;
        $address = $conn->address();

        // If auth is disabled, pass everything through (backward compatible)
        if (!$this->auth->isEnabled()) {
            if ($type === MessageTypes::HELLO) {
                $this->trackPeer($conn, $msg);
            }
            if ($this->onMessage) {
                ($this->onMessage)($conn, $msg);
            }
            return;
        }

        // Auth-layer message handling
        switch ($type) {
            case MessageTypes::HELLO:
                $this->handleHello($conn, $msg);
                return;

            case MessageTypes::AUTH_CHALLENGE:
                $this->handleAuthChallenge($conn, $msg);
                return;

            case MessageTypes::AUTH_RESPONSE:
                $this->handleAuthResponse($conn, $msg);
                return;

            case MessageTypes::AUTH_REJECT:
                $this->log("Auth rejected by {$address}: " . ($msg['reason'] ?? 'unknown'));
                $conn->close();
                return;

            case MessageTypes::PING:
            case MessageTypes::PONG:
                // Allow ping/pong without auth (needed for connection keepalive)
                if ($this->onMessage) {
                    ($this->onMessage)($conn, $msg);
                }
                return;
        }

        // All other messages require authentication
        if (!($this->authenticated[$address] ?? false)) {
            $this->log("Dropping unauthenticated message type " . MessageTypes::name($type) . " from {$address}");
            return;
        }

        if ($this->onMessage) {
            ($this->onMessage)($conn, $msg);
        }
    }

    /**
     * Step 1: Receive HELLO — track peer identity and issue challenge.
     */
    private function handleHello(Connection $conn, array $msg): void
    {
        $peerNodeId = $msg['node_id'] ?? '';
        $peerRole = $msg['role'] ?? 'worker';

        $this->trackPeer($conn, $msg);

        // Issue challenge
        $challenge = $this->auth->createChallenge($conn, $peerNodeId, $peerRole);
        $conn->send($challenge);

        $this->log("Sent auth challenge to {$peerNodeId} ({$peerRole}) at {$conn->address()}");

        // Forward HELLO to the application layer so peer registration happens
        if ($this->onMessage) {
            ($this->onMessage)($conn, $msg);
        }
    }

    /**
     * Step 2: Receive AUTH_CHALLENGE — compute and send response.
     */
    private function handleAuthChallenge(Connection $conn, array $msg): void
    {
        $nonce = $msg['nonce'] ?? '';

        if ($nonce === '') {
            $this->log("Empty nonce in AUTH_CHALLENGE from {$conn->address()}");
            $conn->close();
            return;
        }

        $response = $this->auth->computeResponse($nonce, $this->nodeId, $this->role);
        $conn->send($response);

        $this->log("Sent auth response to {$conn->address()}");
    }

    /**
     * Step 3: Receive AUTH_RESPONSE — verify HMAC.
     */
    private function handleAuthResponse(Connection $conn, array $msg): void
    {
        $result = $this->auth->verifyResponse($conn, $msg);
        $address = $conn->address();

        if ($result->success) {
            $this->authenticated[$address] = true;
            $nodeId = $msg['node_id'] ?? '';
            $role = $msg['role'] ?? '';

            $this->log("Authenticated {$nodeId} ({$role}) at {$address}");

            if ($this->onAuthenticated) {
                ($this->onAuthenticated)($conn, $nodeId, $role);
            }
        } else {
            $this->log("Auth failed for {$address}: {$result->reason}");

            $conn->send([
                'type' => MessageTypes::AUTH_REJECT,
                'reason' => $result->reason,
            ]);

            if ($this->onRejected) {
                ($this->onRejected)($conn, $result->reason);
            }

            $conn->close();
        }
    }

    private function handleDisconnect(Connection $conn): void
    {
        $address = $conn->address();
        $this->auth->cancelPending($conn);
        unset($this->authenticated[$address]);
        unset($this->peerNodeIds[$address]);
        unset($this->peerRoles[$address]);

        if ($this->onDisconnect) {
            ($this->onDisconnect)($conn);
        }
    }

    private function trackPeer(Connection $conn, array $helloMsg): void
    {
        $address = $conn->address();
        $this->peerNodeIds[$address] = $helloMsg['node_id'] ?? '';
        $this->peerRoles[$address] = $helloMsg['role'] ?? 'worker';
    }

    /**
     * Get counts for monitoring.
     */
    public function getStats(): array
    {
        return [
            'auth_enabled' => $this->auth->isEnabled(),
            'authenticated_peers' => count(array_filter($this->authenticated)),
            'tracked_peers' => count($this->peerNodeIds),
        ];
    }

    private function log(string $message): void
    {
        if ($this->logger) {
            ($this->logger)("[auth] {$message}");
        }
    }
}
