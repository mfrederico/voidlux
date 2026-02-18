<?php

declare(strict_types=1);

namespace VoidLux\P2P\Discovery;

use Swoole\Coroutine;
use VoidLux\P2P\PeerManager;
use VoidLux\P2P\Transport\Connection;
use VoidLux\P2P\Transport\TcpMesh;

/**
 * Unified discovery manager: UDP broadcast + PEX + seed peers + DHT.
 * Coordinates all peer discovery mechanisms through a single facade.
 */
class DiscoveryManager
{
    private UdpBroadcast $udp;
    private PeerExchange $pex;
    private SeedPeers $seeds;
    private DhtDiscovery $dht;
    private bool $running = false;

    /** @var callable(string): void */
    private $onLog;

    /** @var array<string, float> nodeId => latency ms */
    private array $latencies = [];

    /** @var array<string, array{host: string, p2p_port: int, http_port: int, role: string}> */
    private array $knownPeers = [];

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
        $this->udp = new UdpBroadcast($this->discoveryPort, $this->p2pPort, $this->nodeId);
        $this->pex = new PeerExchange($this->mesh, $this->peerManager);
        $this->seeds = new SeedPeers($this->seedPeers);
        $this->dht = new DhtDiscovery(
            mesh: $this->mesh,
            peerManager: $this->peerManager,
            nodeId: $this->nodeId,
            p2pPort: $this->p2pPort,
            httpPort: $this->httpPort,
            role: $this->role,
        );
    }

    public function onLog(callable $cb): void
    {
        $this->onLog = $cb;
        $this->dht->onLog($cb);
    }

    private function log(string $msg): void
    {
        if ($this->onLog) {
            ($this->onLog)("[discovery] $msg");
        }
    }

    public function start(): void
    {
        $this->running = true;

        // UDP broadcast for LAN discovery
        $this->udp->onPeerDiscovered(function (string $host, int $port, string $nodeId) {
            if (!$this->peerManager->isConnected($nodeId)) {
                $this->log("UDP discovered: {$nodeId} at {$host}:{$port}");
                $this->peerManager->addKnownAddress($host, $port);
            }
        });
        $this->udp->start();

        // PEX for gossip-based peer sharing
        $this->pex->start();

        // DHT for structured discovery
        $this->dht->onPeerDiscovered(function (string $host, int $port, string $nodeId, string $role) {
            if (!$this->peerManager->isConnected($nodeId)) {
                $this->log("DHT discovered: {$nodeId} at {$host}:{$port} (role: {$role})");
                $this->peerManager->addKnownAddress($host, $port);
            }
        });
        $this->dht->start();

        // Connect to seed peers
        Coroutine::create(function () {
            foreach ($this->seeds->getSeeds() as $seed) {
                $this->log("Connecting to seed: {$seed['host']}:{$seed['port']}");
                $this->peerManager->addKnownAddress($seed['host'], $seed['port']);
            }
        });

        $this->log("Discovery started (UDP:{$this->discoveryPort}, seeds:" . count($this->seedPeers) . ")");
    }

    public function stop(): void
    {
        $this->running = false;
        $this->udp->stop();
        $this->pex->stop();
        $this->dht->stop();
    }

    /**
     * Called when a peer completes HELLO handshake.
     */
    public function onPeerConnected(string $nodeId, string $host, int $p2pPort, int $httpPort, string $role): void
    {
        $this->knownPeers[$nodeId] = [
            'host' => $host,
            'p2p_port' => $p2pPort,
            'http_port' => $httpPort,
            'role' => $role,
        ];
        $this->dht->addPeer($nodeId, $host, $p2pPort, $httpPort, $role);
    }

    /**
     * Called when a peer disconnects.
     */
    public function onPeerDisconnected(string $nodeId): void
    {
        unset($this->knownPeers[$nodeId]);
        unset($this->latencies[$nodeId]);
    }

    /**
     * Handle incoming PEX message.
     * @return array<array{host: string, port: int}>
     */
    public function handlePex(array $msg): array
    {
        return $this->pex->handlePex($msg);
    }

    /**
     * Handle DHT discovery wire messages (LOOKUP, LOOKUP_RSP, ANNOUNCE).
     */
    public function handleDhtMessage(Connection $conn, array $msg): void
    {
        $this->dht->handleMessage($conn, $msg);
    }

    /**
     * Record latency measurement for a peer.
     */
    public function recordLatency(string $nodeId, float $latencyMs): void
    {
        $this->latencies[$nodeId] = $latencyMs;
    }

    /**
     * Get discovery stats for the API.
     */
    public function stats(): array
    {
        return [
            'known_peers' => count($this->knownPeers),
            'dht_routing_table_size' => $this->dht->routingTable()->size(),
            'seed_count' => count($this->seeds->getSeeds()),
            'latencies' => $this->latencies,
            'peers' => array_map(function (array $info, string $nodeId) {
                return [
                    'node_id' => $nodeId,
                    'host' => $info['host'],
                    'p2p_port' => $info['p2p_port'],
                    'http_port' => $info['http_port'],
                    'role' => $info['role'],
                    'latency_ms' => $this->latencies[$nodeId] ?? null,
                ];
            }, $this->knownPeers, array_keys($this->knownPeers)),
        ];
    }
}
