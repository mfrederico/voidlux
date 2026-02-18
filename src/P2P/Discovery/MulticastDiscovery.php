<?php

declare(strict_types=1);

namespace VoidLux\P2P\Discovery;

use Swoole\Coroutine;
use Swoole\Coroutine\Socket;
use VoidLux\P2P\Protocol\MessageTypes;

/**
 * Multicast-based peer discovery for LAN/subnet environments.
 *
 * Uses IPv4 multicast group 239.77.86.76 ("MVL" = multicast VoidLux)
 * instead of 255.255.255.255 broadcast. Multicast is more reliable:
 * - Works across subnets with multicast routing enabled
 * - Doesn't require SO_BROADCAST
 * - Routers can selectively forward via IGMP
 *
 * Complements UdpBroadcast (for pure LAN) and SeedPeers (for WAN).
 */
class MulticastDiscovery
{
    private const MULTICAST_GROUP = '239.77.86.76';
    private const MULTICAST_TTL = 4; // Hops for subnet traversal

    private ?Socket $socket = null;
    private bool $running = false;

    /** @var callable(string, int, string, string): void  host, port, nodeId, role */
    private $onPeerDiscovered;

    public function __construct(
        private readonly int $discoveryPort,
        private readonly int $p2pPort,
        private readonly int $httpPort,
        private readonly string $nodeId,
        private readonly string $role = 'worker',
        private readonly int $announceInterval = 15,
    ) {}

    public function onPeerDiscovered(callable $cb): void
    {
        $this->onPeerDiscovered = $cb;
    }

    public function start(): void
    {
        $this->running = true;

        $this->socket = new Socket(AF_INET, SOCK_DGRAM, defined('IPPROTO_UDP') ? IPPROTO_UDP : SOL_UDP);
        $this->socket->setOption(SOL_SOCKET, SO_REUSEADDR, 1);
        $this->socket->bind('0.0.0.0', $this->discoveryPort);

        // Join multicast group
        $mreq = pack('a4a4', inet_pton(self::MULTICAST_GROUP), inet_pton('0.0.0.0'));
        $this->socket->setOption(IPPROTO_IP, MCAST_JOIN_GROUP, [
            'group' => self::MULTICAST_GROUP,
            'interface' => 0,
        ]);

        // Set multicast TTL for subnet traversal
        $this->socket->setOption(IPPROTO_IP, IP_MULTICAST_TTL, self::MULTICAST_TTL);

        // Disable loopback so we don't receive our own messages
        $this->socket->setOption(IPPROTO_IP, IP_MULTICAST_LOOP, 0);

        Coroutine::create(function () {
            $this->listenLoop();
        });

        Coroutine::create(function () {
            $this->announceLoop();
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
            if (!is_array($message) || ($message['proto'] ?? '') !== 'voidlux-discovery') {
                continue;
            }

            $remoteNodeId = $message['node_id'] ?? '';
            if (!$remoteNodeId || $remoteNodeId === $this->nodeId) {
                continue;
            }

            $remoteHost = $peer['address'] ?? '';
            $remoteP2pPort = (int) ($message['p2p_port'] ?? 0);
            $remoteRole = $message['role'] ?? 'worker';

            if ($this->onPeerDiscovered && $remoteHost && $remoteP2pPort) {
                ($this->onPeerDiscovered)($remoteHost, $remoteP2pPort, $remoteNodeId, $remoteRole);
            }
        }
    }

    private function announceLoop(): void
    {
        $payload = json_encode([
            'proto' => 'voidlux-discovery',
            'version' => 1,
            'node_id' => $this->nodeId,
            'p2p_port' => $this->p2pPort,
            'http_port' => $this->httpPort,
            'role' => $this->role,
            'ts' => time(),
        ]);

        while ($this->running) {
            $this->socket->sendto(self::MULTICAST_GROUP, $this->discoveryPort, $payload);
            Coroutine::sleep($this->announceInterval);
        }
    }

    public function stop(): void
    {
        $this->running = false;
        $this->socket?->close();
    }
}
