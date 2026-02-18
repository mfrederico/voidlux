<?php

declare(strict_types=1);

namespace VoidLux\P2P\Discovery;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use VoidLux\P2P\PeerManager;
use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\Connection;
use VoidLux\P2P\Transport\TcpMesh;

/**
 * Kademlia-inspired DHT discovery engine.
 *
 * Provides structured peer discovery beyond LAN:
 * - Iterative node lookups via XOR distance routing
 * - Self-organizing routing table with k-buckets
 * - Periodic bucket refresh for stale regions
 * - Node announcement propagation through closest peers
 *
 * Integrates with the existing TcpMesh and PeerManager.
 * Uses DHT_* wire protocol messages for lookups.
 *
 * Unlike pure Kademlia, this doesn't store arbitrary key-value data —
 * it focuses on peer discovery and topology maintenance.
 */
class DhtDiscovery
{
    private const ALPHA = 3; // Concurrent lookups per iteration
    private const LOOKUP_TIMEOUT = 5.0; // Seconds per lookup round
    private const REFRESH_INTERVAL = 60; // Seconds between bucket refresh
    private const ANNOUNCE_INTERVAL = 30; // Seconds between self-announcement

    private RoutingTable $routingTable;
    private bool $running = false;

    /** @var array<string, Channel> Pending lookup responses keyed by request ID */
    private array $pendingLookups = [];

    /** @var callable(string, int, string, string): void  host, port, nodeId, role */
    private $onPeerDiscovered;

    /** @var callable(string): void */
    private $onLog;

    public function __construct(
        private readonly TcpMesh $mesh,
        private readonly PeerManager $peerManager,
        private readonly string $nodeId,
        private readonly int $p2pPort,
        private readonly int $httpPort,
        private readonly string $role,
    ) {
        $this->routingTable = new RoutingTable($this->nodeId);
    }

    public function onPeerDiscovered(callable $cb): void
    {
        $this->onPeerDiscovered = $cb;
    }

    public function onLog(callable $cb): void
    {
        $this->onLog = $cb;
    }

    public function routingTable(): RoutingTable
    {
        return $this->routingTable;
    }

    /**
     * Start the DHT discovery loops.
     */
    public function start(): void
    {
        $this->running = true;

        // Bucket refresh loop
        Coroutine::create(function () {
            while ($this->running) {
                Coroutine::sleep(self::REFRESH_INTERVAL);
                $this->refreshStaleBuckets();
            }
        });

        // Self-announcement loop
        Coroutine::create(function () {
            while ($this->running) {
                Coroutine::sleep(self::ANNOUNCE_INTERVAL);
                $this->announceToClosestPeers();
            }
        });

        // Bootstrap: look up ourselves to populate routing table
        Coroutine::create(function () {
            Coroutine::sleep(3); // Wait for initial connections
            $this->bootstrap();
        });
    }

    /**
     * Handle incoming DHT messages from the P2P mesh.
     */
    public function handleMessage(Connection $conn, array $msg): void
    {
        $type = $msg['type'] ?? 0;

        switch ($type) {
            case MessageTypes::DHT_DISC_LOOKUP:
                $this->handleLookupRequest($conn, $msg);
                break;

            case MessageTypes::DHT_DISC_LOOKUP_RSP:
                $this->handleLookupResponse($msg);
                break;

            case MessageTypes::DHT_DISC_ANNOUNCE:
                $this->handleNodeAnnounce($conn, $msg);
                break;
        }
    }

    /**
     * Seed the routing table with a known peer (called on HELLO).
     */
    public function addPeer(string $nodeId, string $host, int $p2pPort, int $httpPort, string $role): void
    {
        $node = new DhtNode(
            nodeId: $nodeId,
            host: $host,
            p2pPort: $p2pPort,
            httpPort: $httpPort,
            role: $role,
            lastSeen: microtime(true),
        );
        $this->routingTable->upsert($node);
    }

    /**
     * Remove a peer from the routing table (called on disconnect).
     */
    public function removePeer(string $nodeId): void
    {
        $this->routingTable->markFailed($nodeId);
    }

    /**
     * Perform an iterative node lookup — the core Kademlia operation.
     * Finds the K closest nodes to targetId across the network.
     *
     * @return DhtNode[]
     */
    public function lookup(string $targetId): array
    {
        // Start with the closest nodes we know
        $closest = $this->routingTable->findClosest($targetId);
        if (empty($closest)) {
            return [];
        }

        $queried = [$this->nodeId => true];
        $seen = [];
        foreach ($closest as $node) {
            $seen[$node->nodeId] = $node;
        }

        // Iterative lookup: query ALPHA closest unqueried nodes per round
        for ($round = 0; $round < 5; $round++) {
            $candidates = $this->sortByDistance($targetId, array_values($seen));
            $toQuery = [];

            foreach ($candidates as $node) {
                if (isset($queried[$node->nodeId])) {
                    continue;
                }
                $toQuery[] = $node;
                if (count($toQuery) >= self::ALPHA) {
                    break;
                }
            }

            if (empty($toQuery)) {
                break; // No more unqueried nodes
            }

            // Query nodes in parallel using channels
            $results = $this->queryNodesParallel($targetId, $toQuery);

            $improved = false;
            foreach ($results as $nodes) {
                foreach ($nodes as $node) {
                    if ($node->nodeId === $this->nodeId) {
                        continue;
                    }
                    if (!isset($seen[$node->nodeId])) {
                        $seen[$node->nodeId] = $node;
                        $this->routingTable->upsert($node);
                        $improved = true;

                        // Notify about discovered peer
                        if ($this->onPeerDiscovered) {
                            ($this->onPeerDiscovered)($node->host, $node->p2pPort, $node->nodeId, $node->role);
                        }
                    }
                }
            }

            foreach ($toQuery as $node) {
                $queried[$node->nodeId] = true;
            }

            if (!$improved) {
                break; // Converged
            }
        }

        // Return K closest to target
        $result = $this->sortByDistance($targetId, array_values($seen));
        return array_slice($result, 0, 8);
    }

    // --- Internal handlers ---

    private function handleLookupRequest(Connection $conn, array $msg): void
    {
        $targetId = $msg['target_id'] ?? '';
        $requestId = $msg['request_id'] ?? '';

        if (!$targetId || !$requestId) {
            return;
        }

        // Touch the requester in our routing table
        $senderId = $conn->getPeerId();
        if ($senderId) {
            $this->routingTable->touch($senderId);
        }

        // Find our closest nodes to the target
        $closest = $this->routingTable->findClosest($targetId);

        // Include ourselves
        $nodes = array_map(fn(DhtNode $n) => $n->toArray(), $closest);
        $nodes[] = [
            'node_id' => $this->nodeId,
            'host' => '0.0.0.0', // Recipient knows our address from the connection
            'p2p_port' => $this->p2pPort,
            'http_port' => $this->httpPort,
            'role' => $this->role,
            'last_seen' => microtime(true),
        ];

        $conn->send([
            'type' => MessageTypes::DHT_DISC_LOOKUP_RSP,
            'request_id' => $requestId,
            'target_id' => $targetId,
            'nodes' => $nodes,
        ]);
    }

    private function handleLookupResponse(array $msg): void
    {
        $requestId = $msg['request_id'] ?? '';
        if (!$requestId || !isset($this->pendingLookups[$requestId])) {
            return;
        }

        $nodes = [];
        foreach ($msg['nodes'] ?? [] as $data) {
            $nodes[] = DhtNode::fromArray($data);
        }

        $channel = $this->pendingLookups[$requestId];
        $channel->push($nodes);
    }

    private function handleNodeAnnounce(Connection $conn, array $msg): void
    {
        $nodeData = $msg['node'] ?? [];
        if (empty($nodeData)) {
            return;
        }

        $node = DhtNode::fromArray($nodeData);
        if ($node->nodeId === $this->nodeId) {
            return;
        }

        // Fix host if it's 0.0.0.0 (self-announce)
        if ($node->host === '0.0.0.0') {
            $node = new DhtNode(
                $node->nodeId, $conn->remoteHost, $node->p2pPort,
                $node->httpPort, $node->role, $node->lastSeen,
            );
        }

        $added = $this->routingTable->upsert($node);

        if ($added && $this->onPeerDiscovered) {
            ($this->onPeerDiscovered)($node->host, $node->p2pPort, $node->nodeId, $node->role);
        }
    }

    /**
     * Query multiple nodes in parallel for the closest nodes to a target.
     *
     * @param DhtNode[] $nodes
     * @return array<DhtNode[]>
     */
    private function queryNodesParallel(string $targetId, array $nodes): array
    {
        $results = [];
        $channels = [];

        foreach ($nodes as $node) {
            $requestId = bin2hex(random_bytes(8));
            $channel = new Channel(1);
            $this->pendingLookups[$requestId] = $channel;
            $channels[] = ['channel' => $channel, 'request_id' => $requestId, 'node' => $node];

            // Send lookup request
            $sent = $this->mesh->sendTo($node->nodeId, [
                'type' => MessageTypes::DHT_DISC_LOOKUP,
                'request_id' => $requestId,
                'target_id' => $targetId,
                'sender_id' => $this->nodeId,
            ]);

            if (!$sent) {
                // Can't reach this node — mark as failed
                $this->routingTable->markFailed($node->nodeId);
                $channel->push([]);
            }
        }

        // Collect results with timeout
        foreach ($channels as $entry) {
            $channel = $entry['channel'];
            $requestId = $entry['request_id'];

            $response = $channel->pop(self::LOOKUP_TIMEOUT);
            $results[] = is_array($response) ? $response : [];

            unset($this->pendingLookups[$requestId]);
            $channel->close();
        }

        return $results;
    }

    // --- Maintenance ---

    /**
     * Bootstrap the DHT by looking up our own ID.
     * This populates the routing table from our immediate peers.
     */
    private function bootstrap(): void
    {
        $this->log("DHT bootstrap: looking up self ({$this->nodeId})");
        $found = $this->lookup($this->nodeId);
        $this->log("DHT bootstrap complete: found " . count($found) . " nodes");
    }

    /**
     * Refresh stale buckets by performing random lookups in their range.
     */
    private function refreshStaleBuckets(): void
    {
        $stale = $this->routingTable->staleBuckets();
        foreach ($stale as $bucketIndex) {
            $randomId = $this->routingTable->randomIdInBucket($bucketIndex);
            Coroutine::create(function () use ($randomId, $bucketIndex) {
                $found = $this->lookup($randomId);
                if (!empty($found)) {
                    $this->log("DHT refresh bucket {$bucketIndex}: found " . count($found) . " nodes");
                }
            });
        }
    }

    /**
     * Announce ourselves to our K closest peers.
     */
    private function announceToClosestPeers(): void
    {
        $closest = $this->routingTable->findClosest($this->nodeId);
        $announce = [
            'type' => MessageTypes::DHT_DISC_ANNOUNCE,
            'node' => [
                'node_id' => $this->nodeId,
                'host' => '0.0.0.0',
                'p2p_port' => $this->p2pPort,
                'http_port' => $this->httpPort,
                'role' => $this->role,
                'last_seen' => microtime(true),
            ],
        ];

        foreach ($closest as $node) {
            $this->mesh->sendTo($node->nodeId, $announce);
        }
    }

    /**
     * @param DhtNode[] $nodes
     * @return DhtNode[]
     */
    private function sortByDistance(string $targetId, array $nodes): array
    {
        usort($nodes, function (DhtNode $a, DhtNode $b) use ($targetId) {
            return strcmp(
                DhtNode::xorDistance($a->nodeId, $targetId),
                DhtNode::xorDistance($b->nodeId, $targetId),
            );
        });
        return $nodes;
    }

    private function log(string $msg): void
    {
        if ($this->onLog) {
            ($this->onLog)($msg);
        }
    }

    public function stop(): void
    {
        $this->running = false;
        // Clean up pending lookups
        foreach ($this->pendingLookups as $requestId => $channel) {
            $channel->push([]);
            unset($this->pendingLookups[$requestId]);
        }
    }
}
