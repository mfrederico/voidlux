<?php

declare(strict_types=1);

namespace VoidLux\P2P\Discovery;

use Swoole\Coroutine;
use VoidLux\P2P\PeerManager;
use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\TcpMesh;

/**
 * Gossip-based peer exchange (PEX).
 * Periodically shares known peer lists with connected peers.
 */
class PeerExchange
{
    private bool $running = false;

    public function __construct(
        private readonly TcpMesh $mesh,
        private readonly PeerManager $peerManager,
        private readonly int $interval = 30,
    ) {}

    public function start(): void
    {
        $this->running = true;

        Coroutine::create(function () {
            while ($this->running) {
                Coroutine::sleep($this->interval);
                $this->exchangePeers();
            }
        });
    }

    private function exchangePeers(): void
    {
        $peers = [];
        foreach ($this->peerManager->getConnectedPeers() as $info) {
            $peers[] = [
                'host' => $info['host'],
                'port' => $info['port'],
                'node_id' => $info['node_id'],
            ];
        }

        if (empty($peers)) {
            return;
        }

        $this->mesh->broadcast([
            'type' => MessageTypes::PEX,
            'peers' => $peers,
        ]);
    }

    /**
     * Handle incoming PEX message. Returns list of new peers to connect to.
     * @return array<array{host: string, port: int}>
     */
    public function handlePex(array $message): array
    {
        $newPeers = [];
        foreach ($message['peers'] ?? [] as $peer) {
            $host = $peer['host'] ?? '';
            $port = $peer['port'] ?? 0;
            $nodeId = $peer['node_id'] ?? '';

            if ($host && $port && !$this->peerManager->isConnected($nodeId)) {
                $newPeers[] = ['host' => $host, 'port' => $port];
            }
        }
        return $newPeers;
    }

    public function stop(): void
    {
        $this->running = false;
    }
}
