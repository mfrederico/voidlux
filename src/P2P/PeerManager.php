<?php

declare(strict_types=1);

namespace VoidLux\P2P;

use Swoole\Coroutine;
use VoidLux\P2P\Transport\Connection;
use VoidLux\P2P\Transport\TcpMesh;
use VoidLux\P2P\Protocol\MessageTypes;

/**
 * Manages peer registry, connection lifecycle, reconnection, and PING/PONG keepalive.
 */
class PeerManager
{
    public const MAX_CONNECTIONS = 20;
    private const PING_INTERVAL = 15;
    private const RECONNECT_INTERVAL = 10;

    /** @var array<string, array{host: string, port: int, node_id: string, connected_at: float}> keyed by node_id */
    private array $peers = [];

    /** @var array<string, array{host: string, port: int, last_attempt: float}> keyed by address */
    private array $knownAddresses = [];

    private bool $running = false;

    public function __construct(
        private readonly TcpMesh $mesh,
        private readonly string $nodeId,
    ) {}

    public function start(): void
    {
        $this->running = true;

        // Keepalive loop
        Coroutine::create(function () {
            while ($this->running) {
                Coroutine::sleep(self::PING_INTERVAL);
                $this->pingAll();
            }
        });

        // Reconnection loop
        Coroutine::create(function () {
            while ($this->running) {
                Coroutine::sleep(self::RECONNECT_INTERVAL);
                $this->reconnectLoop();
            }
        });
    }

    public function registerPeer(Connection $conn, string $nodeId, string $host, int $port): void
    {
        if ($nodeId === $this->nodeId) {
            $conn->close();
            return;
        }

        $conn->setPeerId($nodeId);
        $this->peers[$nodeId] = [
            'host' => $host,
            'port' => $port,
            'node_id' => $nodeId,
            'connected_at' => microtime(true),
        ];
        $this->knownAddresses["{$host}:{$port}"] = [
            'host' => $host,
            'port' => $port,
            'last_attempt' => 0,
        ];
    }

    public function unregisterPeer(Connection $conn): void
    {
        $nodeId = $conn->getPeerId();
        if ($nodeId) {
            unset($this->peers[$nodeId]);
        }
    }

    public function addKnownAddress(string $host, int $port): void
    {
        $address = "{$host}:{$port}";
        if (!isset($this->knownAddresses[$address])) {
            $this->knownAddresses[$address] = [
                'host' => $host,
                'port' => $port,
                'last_attempt' => 0,
            ];
        }
    }

    public function isConnected(string $nodeId): bool
    {
        return isset($this->peers[$nodeId]);
    }

    /**
     * @return array<string, array{host: string, port: int, node_id: string}>
     */
    public function getConnectedPeers(): array
    {
        return $this->peers;
    }

    public function getPeerCount(): int
    {
        return count($this->peers);
    }

    private function pingAll(): void
    {
        $this->mesh->broadcast([
            'type' => MessageTypes::PING,
            'node_id' => $this->nodeId,
            'timestamp' => microtime(true),
        ]);
    }

    private function reconnectLoop(): void
    {
        if ($this->mesh->getConnectionCount() >= self::MAX_CONNECTIONS) {
            return;
        }

        $now = microtime(true);
        foreach ($this->knownAddresses as $address => &$info) {
            if ($this->mesh->getConnectionCount() >= self::MAX_CONNECTIONS) {
                break;
            }

            // Skip recently attempted
            if ($now - $info['last_attempt'] < self::RECONNECT_INTERVAL * 3) {
                continue;
            }

            // Skip if already connected to this address
            $connections = $this->mesh->getConnections();
            if (isset($connections[$address])) {
                continue;
            }

            $info['last_attempt'] = $now;
            $this->mesh->connectTo($info['host'], $info['port']);
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }
}
