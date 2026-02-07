<?php

declare(strict_types=1);

namespace VoidLux\P2P\Transport;

use Swoole\Coroutine;
use Swoole\Coroutine\Socket;

/**
 * TCP mesh: listens for inbound connections and maintains outbound connections.
 */
class TcpMesh
{
    private ?Socket $serverSocket = null;
    /** @var Connection[] keyed by address */
    private array $connections = [];
    private bool $running = false;

    /** @var callable(Connection): void */
    private $onConnection;
    /** @var callable(Connection, array): void */
    private $onMessage;
    /** @var callable(Connection): void */
    private $onDisconnect;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $nodeId,
    ) {}

    public function onConnection(callable $cb): void
    {
        $this->onConnection = $cb;
    }

    public function onMessage(callable $cb): void
    {
        $this->onMessage = $cb;
    }

    public function onDisconnect(callable $cb): void
    {
        $this->onDisconnect = $cb;
    }

    public function start(): void
    {
        $this->running = true;

        // Start TCP server
        $this->serverSocket = new Socket(AF_INET, SOCK_STREAM, defined('IPPROTO_TCP') ? IPPROTO_TCP : SOL_TCP);
        $this->serverSocket->setOption(SOL_SOCKET, SO_REUSEADDR, 1);
        $this->serverSocket->setOption(SOL_SOCKET, SO_REUSEPORT, 1);
        $this->serverSocket->bind($this->host, $this->port);
        $this->serverSocket->listen(128);

        Coroutine::create(function () {
            $this->acceptLoop();
        });
    }

    private function acceptLoop(): void
    {
        while ($this->running) {
            $client = $this->serverSocket->accept(1.0);
            if ($client === false) {
                continue;
            }

            $peerInfo = $client->getpeername();
            $conn = new Connection(
                $client,
                $peerInfo['address'] ?? 'unknown',
                $peerInfo['port'] ?? 0,
                inbound: true,
            );

            Coroutine::create(function () use ($conn) {
                $this->handleConnection($conn);
            });
        }
    }

    /**
     * Connect to a remote peer.
     */
    public function connectTo(string $host, int $port): ?Connection
    {
        $address = "{$host}:{$port}";
        if (isset($this->connections[$address])) {
            $existing = $this->connections[$address];
            if (!$existing->isClosed()) {
                return $existing;
            }
        }

        $socket = new Socket(AF_INET, SOCK_STREAM, defined('IPPROTO_TCP') ? IPPROTO_TCP : SOL_TCP);
        if (!$socket->connect($host, $port, 5.0)) {
            return null;
        }

        $conn = new Connection($socket, $host, $port, inbound: false);

        Coroutine::create(function () use ($conn) {
            $this->handleConnection($conn);
        });

        return $conn;
    }

    private function handleConnection(Connection $conn): void
    {
        $address = $conn->address();
        $this->connections[$address] = $conn;

        if ($this->onConnection) {
            ($this->onConnection)($conn);
        }

        while ($this->running && !$conn->isClosed()) {
            $message = $conn->receive(5.0);
            if ($message === null) {
                if ($conn->isClosed()) {
                    break;
                }
                // Timeout, check if still alive
                if (microtime(true) - $conn->getLastActivity() > 60.0) {
                    break;
                }
                continue;
            }

            if ($this->onMessage) {
                ($this->onMessage)($conn, $message);
            }
        }

        $conn->close();
        unset($this->connections[$address]);

        if ($this->onDisconnect) {
            ($this->onDisconnect)($conn);
        }
    }

    /**
     * Broadcast a message to all connected peers.
     * @param string|null $excludeAddress Address to exclude (e.g., the sender)
     */
    public function broadcast(array $message, ?string $excludeAddress = null): int
    {
        $sent = 0;
        foreach ($this->connections as $address => $conn) {
            if ($address === $excludeAddress) {
                continue;
            }
            if (!$conn->isClosed() && $conn->send($message)) {
                $sent++;
            }
        }
        return $sent;
    }

    /**
     * @return Connection[]
     */
    public function getConnections(): array
    {
        return $this->connections;
    }

    public function getConnectionCount(): int
    {
        return count($this->connections);
    }

    public function stop(): void
    {
        $this->running = false;
        foreach ($this->connections as $conn) {
            $conn->close();
        }
        $this->connections = [];
        $this->serverSocket?->close();
    }
}
