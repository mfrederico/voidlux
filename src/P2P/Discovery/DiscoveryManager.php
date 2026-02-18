<?php

declare(strict_types=1);

namespace VoidLux\P2P\Discovery;

use Swoole\Coroutine;
use VoidLux\P2P\PeerManager;
use VoidLux\P2P\Transport\Connection;
use VoidLux\P2P\Transport\TcpMesh;

/**
 * Unified discovery manager combining all peer discovery mechanisms.
 *
 * Provides a single entry point for:
 * - UDP broadcast (LAN same-subnet)
 * - UDP multicast (LAN cross-subnet with IGMP)
 * - Seed peers (WAN bootstrap)
 * - Peer Exchange / PEX (gossip-based peer sharing)
 * - DHT (Kademlia-style structured discovery)
 * - Network topology tracking (latency, roles, partitioning)
 *
 * New nodes join without any central directory:
 * 1. First try UDP multicast/broadcast for LAN peers
 * 2. Fall back to seed peers if configured
 * 3. Once connected, DHT + PEX discover the full network
 * 4. Periodic refresh maintains awareness as nodes join/leave
 */
class DiscoveryManager
{
    private ?UdpBroadcast $udpBroadcast = null;
    private ?MulticastDiscovery $multicast = null;
    private ?DhtDiscovery $dht = null;
    private ?PeerExchange $pex = null;
    private NetworkTopology $topology;
    private bool $running = false;

    /** @var callable(string): void */
    private $onLog;

    public function __construct(
        private readonly TcpMesh $mesh,
        private readonly PeerManager $peerManager,
        private readonly string $nodeId,
        private readonly int $p2pPort,
        private readonly int $httpPort,
        private readonly string $role,
        private readonly int $discoveryPort = 6101,
        private readonly array $seedPeers = [],
    ) {
        $this->topology = new NetworkTopology($this->nodeId);
    }

    public function onLog(callable $cb): void
    {
        $this->onLog = $cb;
    }

    public function topology(): NetworkTopology
    {
        return $this->topology;
    }

    public function dht(): ?DhtDiscovery
    {
        return $this->dht;
    }

    public function udpBroadcast(): ?UdpBroadcast
    {
        return $this->udpBroadcast;
    }

    public function multicast(): ?MulticastDiscovery
    {
        return $this->multicast;
    }

    public function pex(): ?PeerExchange
    {
        return $this->pex;
    }

    /**
     * Start all discovery mechanisms.
     */
    public function start(): void
    {
        $this->running = true;

        // 1. UDP broadcast (LAN)
        $this->udpBroadcast = new UdpBroadcast($this->discoveryPort, $this->p2pPort, $this->nodeId);
        $this->udpBroadcast->onPeerDiscovered(function (string $host, int $port, string $nodeId) {
            $this->onDiscovered($host, $port, $nodeId, 'worker', 'udp_broadcast');
        });

        Coroutine::create(function () {
            $this->udpBroadcast->start();
        });

        // 2. Multicast discovery (LAN cross-subnet)
        $this->multicast = new MulticastDiscovery(
            $this->discoveryPort + 1, // Separate port from broadcast
            $this->p2pPort,
            $this->httpPort,
            $this->nodeId,
            $this->role,
        );
        $this->multicast->onPeerDiscovered(function (string $host, int $port, string $nodeId, string $role) {
            $this->onDiscovered($host, $port, $nodeId, $role, 'multicast');
        });

        Coroutine::create(function () {
            $this->multicast->start();
        });

        // 3. PEX (gossip-based peer sharing)
        $this->pex = new PeerExchange($this->mesh, $this->peerManager);

        Coroutine::create(function () {
            $this->pex->start();
        });

        // 4. DHT (structured discovery)
        $this->dht = new DhtDiscovery(
            $this->mesh,
            $this->peerManager,
            $this->nodeId,
            $this->p2pPort,
            $this->httpPort,
            $this->role,
        );
        $this->dht->onPeerDiscovered(function (string $host, int $port, string $nodeId, string $role) {
            $this->onDiscovered($host, $port, $nodeId, $role, 'dht');
        });
        $this->dht->onLog(function (string $msg) {
            $this->log($msg);
        });

        Coroutine::create(function () {
            $this->dht->start();
        });

        // 5. Seed peers (WAN bootstrap)
        $seeds = new SeedPeers($this->seedPeers);
        foreach ($seeds->getSeeds() as $seed) {
            $this->peerManager->addKnownAddress($seed['host'], $seed['port']);
            Coroutine::create(function () use ($seed) {
                $this->log("Connecting to seed peer: {$seed['host']}:{$seed['port']}");
                $this->mesh->connectTo($seed['host'], $seed['port']);
            });
        }

        // 6. Periodic topology maintenance
        Coroutine::create(function () {
            while ($this->running) {
                Coroutine::sleep(120);
                $pruned = $this->topology->pruneStale();
                if ($pruned > 0) {
                    $this->log("Topology: pruned {$pruned} stale peer(s)");
                }
            }
        });

        $this->log("Discovery started: broadcast + multicast + PEX + DHT");
    }

    /**
     * Handle a new peer connection (from HELLO handshake).
     * Call this when a peer is registered in PeerManager.
     */
    public function onPeerConnected(string $nodeId, string $host, int $p2pPort, int $httpPort, string $role): void
    {
        $this->topology->observePeer($nodeId, $host, $p2pPort, $httpPort, $role, connected: true);
        $this->dht?->addPeer($nodeId, $host, $p2pPort, $httpPort, $role);
    }

    /**
     * Handle peer disconnection.
     */
    public function onPeerDisconnected(string $nodeId): void
    {
        $this->topology->peerDisconnected($nodeId);
        $this->dht?->removePeer($nodeId);
    }

    /**
     * Record a latency measurement (from PONG).
     */
    public function recordLatency(string $nodeId, float $latencyMs): void
    {
        $this->topology->recordLatency($nodeId, $latencyMs);
    }

    /**
     * Handle PEX messages.
     * @return array<array{host: string, port: int}>
     */
    public function handlePex(array $msg): array
    {
        $newPeers = $this->pex?->handlePex($msg) ?? [];

        // Also add PEX peers to topology as observed (not connected)
        foreach ($msg['peers'] ?? [] as $peer) {
            $nodeId = $peer['node_id'] ?? '';
            if ($nodeId && $nodeId !== $this->nodeId) {
                $this->topology->observePeer(
                    $nodeId,
                    $peer['host'] ?? '',
                    (int) ($peer['port'] ?? 0),
                    0,
                    'worker',
                    connected: false,
                );
            }
        }

        return $newPeers;
    }

    /**
     * Handle DHT protocol messages.
     */
    public function handleDhtMessage(Connection $conn, array $msg): void
    {
        $this->dht?->handleMessage($conn, $msg);
    }

    /**
     * Get unified discovery statistics.
     */
    public function stats(): array
    {
        return [
            'topology' => $this->topology->stats(),
            'dht_routing_table' => $this->dht?->routingTable()->stats() ?? [],
            'discovery_port' => $this->discoveryPort,
            'seed_peers' => count($this->seedPeers),
        ];
    }

    /**
     * Unified handler for newly discovered peers from any source.
     */
    private function onDiscovered(string $host, int $port, string $nodeId, string $role, string $source): void
    {
        if ($nodeId === $this->nodeId) {
            return;
        }

        if ($this->peerManager->isConnected($nodeId)) {
            return; // Already connected
        }

        $this->topology->observePeer($nodeId, $host, $port, 0, $role, connected: false);
        $this->peerManager->addKnownAddress($host, $port);

        $this->log("Discovered peer via {$source}: {$host}:{$port} ({$nodeId})");

        Coroutine::create(function () use ($host, $port) {
            $this->mesh->connectTo($host, $port);
        });
    }

    private function log(string $msg): void
    {
        if ($this->onLog) {
            ($this->onLog)("[discovery] {$msg}");
        }
    }

    public function stop(): void
    {
        $this->running = false;
        $this->udpBroadcast?->stop();
        $this->multicast?->stop();
        $this->pex?->stop();
        $this->dht?->stop();
    }
}
