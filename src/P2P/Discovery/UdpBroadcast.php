<?php

declare(strict_types=1);

namespace VoidLux\P2P\Discovery;

use Swoole\Coroutine;
use Swoole\Coroutine\Socket;
use VoidLux\P2P\Protocol\MessageTypes;

/**
 * LAN discovery via UDP broadcast.
 * Broadcasts HELLO messages and listens for peers on the local network.
 */
class UdpBroadcast
{
    private ?Socket $socket = null;
    private bool $running = false;

    /** @var callable(string, int, string): void  host, port, nodeId */
    private $onPeerDiscovered;

    public function __construct(
        private readonly int $discoveryPort,
        private readonly int $p2pPort,
        private readonly string $nodeId,
    ) {}

    public function onPeerDiscovered(callable $cb): void
    {
        $this->onPeerDiscovered = $cb;
    }

    public function start(): void
    {
        $this->running = true;

        $this->socket = new Socket(AF_INET, SOCK_DGRAM, defined('IPPROTO_UDP') ? IPPROTO_UDP : SOL_UDP);
        $this->socket->setOption(SOL_SOCKET, SO_BROADCAST, 1);
        $this->socket->setOption(SOL_SOCKET, SO_REUSEADDR, 1);
        $this->socket->bind('0.0.0.0', $this->discoveryPort);

        // Listen for broadcasts
        Coroutine::create(function () {
            $this->listenLoop();
        });

        // Broadcast presence periodically
        Coroutine::create(function () {
            $this->broadcastLoop();
        });
    }

    private function listenLoop(): void
    {
        while ($this->running) {
            $peer = $this->socket->recvfrom($data, 1.0);
            if ($peer === false || $data === false || $data === '') {
                continue;
            }

            $message = @json_decode($data, true);
            if (!$message || ($message['type'] ?? null) !== MessageTypes::HELLO) {
                continue;
            }

            $remoteNodeId = $message['node_id'] ?? '';
            if ($remoteNodeId === $this->nodeId) {
                continue; // Ignore our own broadcasts
            }

            $remoteHost = $peer['address'] ?? '';
            $remoteP2pPort = $message['p2p_port'] ?? $this->p2pPort;

            if ($this->onPeerDiscovered && $remoteHost) {
                ($this->onPeerDiscovered)($remoteHost, $remoteP2pPort, $remoteNodeId);
            }
        }
    }

    private function broadcastLoop(): void
    {
        $hello = json_encode([
            'type' => MessageTypes::HELLO,
            'node_id' => $this->nodeId,
            'p2p_port' => $this->p2pPort,
        ]);

        while ($this->running) {
            $this->socket->sendto('255.255.255.255', $this->discoveryPort, $hello);
            Coroutine::sleep(10);
        }
    }

    public function stop(): void
    {
        $this->running = false;
        $this->socket?->close();
    }
}
