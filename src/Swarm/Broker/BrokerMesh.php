<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Broker;

use Swoole\Coroutine;
use Swoole\Http\Request;
use Swoole\Http\Response;
use VoidLux\P2P\Protocol\LamportClock;
use VoidLux\P2P\Transport\Connection;
use VoidLux\P2P\Transport\TcpMesh;
use VoidLux\Swarm\Galactic\BountyBoard;
use VoidLux\Swarm\Galactic\BountyModel;
use VoidLux\Swarm\Galactic\CapabilityProfile;

/**
 * Manages Seneschal-to-Seneschal (broker) connections.
 * Provides marketplace message relay across swarm boundaries.
 *
 * Uses a separate TcpMesh on a dedicated port so broker traffic
 * doesn't mix with intra-swarm P2P traffic.
 */
class BrokerMesh
{
    private TcpMesh $mesh;
    private BountyBoard $bountyBoard;

    /** @var array<string, array{node_id: string, swarm_name: string, broker_port: int}> keyed by node_id */
    private array $brokerPeers = [];

    /** @var array<string, true> Seen relay message IDs for dedup */
    private array $seenRelays = [];
    private int $seenLimit = 10000;

    /** @var callable|null */
    private $onBountyUpdate = null;

    private ?\Closure $logger;

    /**
     * Marketplace message types relayed from the intra-swarm P2P mesh.
     * Uses raw hex values so this code works before the wire protocol
     * branch's MessageTypes constants are merged in.
     */
    private const MARKETPLACE_TYPES = [
        0xC0, // OFFERING_ANNOUNCE
        0xC1, // OFFERING_WITHDRAW
        0xC5, // CAPABILITY_ADVERTISE
        0xC8, // BOUNTY_POST
        0xC9, // BOUNTY_CLAIM
        0xCA, // BOUNTY_CANCEL
    ];

    public function __construct(
        private readonly string $nodeId,
        private readonly int $brokerPort,
        private readonly LamportClock $clock,
        private readonly array $brokerSeeds = [],
        private readonly string $swarmName = '',
        private readonly string $localEmperorNodeId = '',
        ?\Closure $logger = null,
    ) {
        $this->logger = $logger;
        $this->bountyBoard = new BountyBoard();
        $this->mesh = new TcpMesh('0.0.0.0', $this->brokerPort, $this->nodeId);
    }

    public function getBountyBoard(): BountyBoard
    {
        return $this->bountyBoard;
    }

    public function onBountyUpdate(callable $cb): void
    {
        $this->onBountyUpdate = $cb;
    }

    /**
     * @return array<string, array{node_id: string, swarm_name: string}>
     */
    public function getBrokerPeers(): array
    {
        return $this->brokerPeers;
    }

    public function start(): void
    {
        $this->mesh->onConnection(function (Connection $conn) {
            $conn->send([
                'type' => BrokerMessageTypes::HELLO,
                'node_id' => $this->nodeId,
                'swarm_name' => $this->swarmName,
                'broker_port' => $this->brokerPort,
            ]);
        });

        $this->mesh->onMessage(function (Connection $conn, array $msg) {
            $this->onBrokerMessage($conn, $msg);
        });

        $this->mesh->onDisconnect(function (Connection $conn) {
            $peerId = $conn->getPeerId();
            if ($peerId) {
                unset($this->brokerPeers[$peerId]);
                $this->log("Broker peer disconnected: " . substr($peerId, 0, 8));
            }
        });

        Coroutine::create(function () {
            $this->mesh->start();
        });

        // Connect to broker seeds
        foreach ($this->brokerSeeds as $seed) {
            $parts = explode(':', $seed);
            if (count($parts) === 2) {
                $host = $parts[0];
                $port = (int) $parts[1];
                Coroutine::create(function () use ($host, $port) {
                    $this->log("Connecting to broker seed: {$host}:{$port}");
                    $this->mesh->connectTo($host, $port);
                });
            }
        }

        // Periodic anti-entropy sync with random broker peer
        Coroutine::create(function () {
            while (true) {
                Coroutine::sleep(120);
                $this->syncWithRandomPeer();
            }
        });

        // Periodic seen-messages cleanup
        Coroutine::create(function () {
            while (true) {
                Coroutine::sleep(300);
                if (count($this->seenRelays) > $this->seenLimit) {
                    $this->seenRelays = array_slice($this->seenRelays, -($this->seenLimit / 2), null, true);
                }
            }
        });

        $this->log("Broker mesh started on port {$this->brokerPort}");
    }

    // ── Broker Wire Protocol ───────────────────────────────────────────

    private function onBrokerMessage(Connection $conn, array $msg): void
    {
        $type = $msg['type'] ?? 0;

        switch ($type) {
            case BrokerMessageTypes::HELLO:
                $peerId = $msg['node_id'] ?? '';
                $swarmName = $msg['swarm_name'] ?? '';
                if ($peerId && $peerId !== $this->nodeId) {
                    $conn->setPeerId($peerId);
                    $this->mesh->registerNodeConnection($peerId, $conn);
                    $this->brokerPeers[$peerId] = [
                        'node_id' => $peerId,
                        'swarm_name' => $swarmName,
                        'broker_port' => $msg['broker_port'] ?? 0,
                    ];
                    $short = substr($peerId, 0, 8);
                    $this->log("Broker peer connected: {$short} (swarm: {$swarmName})");

                    // Send our current bounty board snapshot to new peer
                    $conn->send([
                        'type' => BrokerMessageTypes::SYNC_RSP,
                        'node_id' => $this->nodeId,
                        'snapshot' => $this->bountyBoard->buildSnapshot(),
                    ]);
                }
                break;

            case BrokerMessageTypes::PING:
                $conn->send([
                    'type' => BrokerMessageTypes::PONG,
                    'node_id' => $this->nodeId,
                    'timestamp' => $msg['timestamp'] ?? 0,
                ]);
                break;

            case BrokerMessageTypes::PONG:
                break;

            case BrokerMessageTypes::RELAY:
                $this->handleRelay($conn, $msg);
                break;

            case BrokerMessageTypes::SYNC_REQ:
                $conn->send([
                    'type' => BrokerMessageTypes::SYNC_RSP,
                    'node_id' => $this->nodeId,
                    'snapshot' => $this->bountyBoard->buildSnapshot(),
                ]);
                break;

            case BrokerMessageTypes::SYNC_RSP:
                $snapshot = $msg['snapshot'] ?? [];
                $this->bountyBoard->mergeSnapshot($snapshot);
                $senderShort = substr($msg['node_id'] ?? '', 0, 8);
                $this->log("Merged bounty snapshot from {$senderShort}");
                $this->fireBountyUpdate();
                break;
        }
    }

    /**
     * Handle a relayed marketplace message. Dedup + re-relay to other broker peers.
     */
    private function handleRelay(Connection $conn, array $msg): void
    {
        $relayId = $msg['relay_id'] ?? '';
        if (!$relayId || isset($this->seenRelays[$relayId])) {
            return;
        }
        $this->seenRelays[$relayId] = true;

        $innerType = $msg['inner_type'] ?? 0;
        $payload = $msg['payload'] ?? [];
        $this->clock->witness($payload['lamport_ts'] ?? 0);

        switch ($innerType) {
            case 0xC8: // BOUNTY_POST
                $bounty = BountyModel::fromArray($payload);
                if ($this->bountyBoard->receiveBounty($bounty)) {
                    $this->log("Bounty received via relay: {$bounty->title}");
                    $this->fireBountyUpdate();
                }
                break;

            case 0xC9: // BOUNTY_CLAIM
                $bountyId = $payload['bounty_id'] ?? '';
                $nodeId = $payload['node_id'] ?? '';
                $ts = (int) ($payload['lamport_ts'] ?? 0);
                $claimed = $this->bountyBoard->claimBounty($bountyId, $nodeId, $ts);
                if ($claimed) {
                    $this->log("Bounty claimed via relay: {$bountyId}");
                    $this->fireBountyUpdate();
                }
                break;

            case 0xCA: // BOUNTY_CANCEL
                $bountyId = $payload['bounty_id'] ?? '';
                $originId = $payload['posted_by_node_id'] ?? '';
                $ts = (int) ($payload['lamport_ts'] ?? 0);
                $cancelled = $this->bountyBoard->cancelBounty($bountyId, $originId, $ts);
                if ($cancelled) {
                    $this->log("Bounty cancelled via relay: {$bountyId}");
                    $this->fireBountyUpdate();
                }
                break;

            case 0xC5: // CAPABILITY_ADVERTISE
                $profile = CapabilityProfile::fromArray($payload);
                if ($this->bountyBoard->updateCapability($profile)) {
                    $this->log("Capability update from node " . substr($profile->nodeId, 0, 8));
                }
                break;
        }

        // Re-relay to all other broker peers (flood with dedup)
        $senderAddress = $conn->address();
        $this->mesh->broadcast($msg, $senderAddress);
    }

    // ── Outbound Operations ────────────────────────────────────────────

    /**
     * Called when the local swarm P2P mesh receives a marketplace message.
     * Checks if it should be relayed to the broker network.
     */
    public function onSwarmMessage(int $type, array $msg): void
    {
        if (!in_array($type, self::MARKETPLACE_TYPES, true)) {
            return;
        }

        // Strip the type key and relay inner payload
        $payload = $msg;
        unset($payload['type']);

        $this->relayToAll($type, $payload);
    }

    /**
     * Post a bounty to the broker network.
     */
    public function postBounty(BountyModel $bounty): void
    {
        $data = array_merge($bounty->toArray(), ['lamport_ts' => $this->clock->tick()]);
        $bountyWithTs = BountyModel::fromArray($data);
        $this->bountyBoard->postBounty($bountyWithTs);
        $this->relayToAll(0xC8, $bountyWithTs->toArray());
        $this->fireBountyUpdate();
    }

    /**
     * Claim a bounty on the broker network.
     */
    public function claimBounty(string $bountyId, string $nodeId): ?BountyModel
    {
        $ts = $this->clock->tick();
        $claimed = $this->bountyBoard->claimBounty($bountyId, $nodeId, $ts);
        if ($claimed) {
            $this->relayToAll(0xC9, [
                'bounty_id' => $bountyId,
                'node_id' => $nodeId,
                'lamport_ts' => $ts,
            ]);
            $this->fireBountyUpdate();
        }
        return $claimed;
    }

    /**
     * Cancel a bounty on the broker network.
     */
    public function cancelBounty(string $bountyId): ?BountyModel
    {
        $ts = $this->clock->tick();
        $cancelled = $this->bountyBoard->cancelBounty($bountyId, $this->nodeId, $ts);
        if ($cancelled) {
            $this->relayToAll(0xCA, [
                'bounty_id' => $bountyId,
                'posted_by_node_id' => $this->nodeId,
                'lamport_ts' => $ts,
            ]);
            $this->fireBountyUpdate();
        }
        return $cancelled;
    }

    /**
     * Advertise capabilities to the broker network.
     */
    public function advertiseCapabilities(CapabilityProfile $profile): void
    {
        $data = array_merge($profile->toArray(), ['lamport_ts' => $this->clock->tick()]);
        $profileWithTs = CapabilityProfile::fromArray($data);
        $this->bountyBoard->updateCapability($profileWithTs);
        $this->relayToAll(0xC5, $profileWithTs->toArray());
    }

    // ── Relay ──────────────────────────────────────────────────────────

    /**
     * Wrap a message in BROKER_RELAY envelope and broadcast to all broker peers.
     */
    private function relayToAll(int $innerType, array $payload): void
    {
        $relayId = bin2hex(random_bytes(16));
        $this->seenRelays[$relayId] = true;

        $this->mesh->broadcast([
            'type' => BrokerMessageTypes::RELAY,
            'relay_id' => $relayId,
            'inner_type' => $innerType,
            'payload' => $payload,
            'origin_node_id' => $this->nodeId,
        ]);
    }

    private function syncWithRandomPeer(): void
    {
        if (empty($this->brokerPeers)) {
            return;
        }
        $peerId = array_rand($this->brokerPeers);
        $this->mesh->sendTo($peerId, [
            'type' => BrokerMessageTypes::SYNC_REQ,
            'node_id' => $this->nodeId,
        ]);
        $this->log("Anti-entropy sync requested from broker " . substr($peerId, 0, 8));
    }

    // ── HTTP API ───────────────────────────────────────────────────────

    /**
     * Handle /api/broker/* HTTP requests directly at the Seneschal.
     */
    public function handleApi(Request $request, Response $response): void
    {
        $response->header('Content-Type', 'application/json');
        $response->header('Access-Control-Allow-Origin', '*');
        $response->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->header('Access-Control-Allow-Headers', 'Content-Type');

        $uri = $request->server['request_uri'] ?? '';
        $method = strtoupper($request->server['request_method'] ?? 'GET');

        if ($method === 'OPTIONS') {
            $response->status(204);
            $response->end('');
            return;
        }

        // GET /api/broker/status
        if ($uri === '/api/broker/status' && $method === 'GET') {
            $this->jsonResponse($response, [
                'node_id' => $this->nodeId,
                'swarm_name' => $this->swarmName,
                'broker_port' => $this->brokerPort,
                'broker_peers' => count($this->brokerPeers),
                'open_bounties' => count($this->bountyBoard->getOpenBounties()),
                'capabilities' => count($this->bountyBoard->getCapabilities()),
            ]);
            return;
        }

        // GET /api/broker/bounties
        if ($uri === '/api/broker/bounties' && $method === 'GET') {
            $this->jsonResponse($response, [
                'bounties' => array_map(fn(BountyModel $b) => $b->toArray(), $this->bountyBoard->getAllBounties()),
            ]);
            return;
        }

        // POST /api/broker/bounties
        if ($uri === '/api/broker/bounties' && $method === 'POST') {
            $body = json_decode($request->getContent() ?: '{}', true) ?? [];
            $postedBy = $this->localEmperorNodeId ?: $this->nodeId;
            $bounty = BountyModel::create(
                postedByNodeId: $postedBy,
                title: $body['title'] ?? 'Untitled Bounty',
                description: $body['description'] ?? '',
                requiredCapabilities: $body['required_capabilities'] ?? [],
                reward: (int) ($body['reward'] ?? 10),
                currency: $body['currency'] ?? 'VOID',
                ttlSeconds: (int) ($body['ttl_seconds'] ?? 600),
            );
            $this->postBounty($bounty);
            $this->jsonResponse($response, ['bounty' => $bounty->toArray()]);
            return;
        }

        // POST /api/broker/bounties/{id}/claim
        if (preg_match('#^/api/broker/bounties/([a-f0-9-]+)/claim$#', $uri, $m) && $method === 'POST') {
            $bountyId = $m[1];
            $claimingNode = $this->localEmperorNodeId ?: $this->nodeId;
            $claimed = $this->claimBounty($bountyId, $claimingNode);
            if ($claimed) {
                $this->jsonResponse($response, ['bounty' => $claimed->toArray()]);
            } else {
                $response->status(409);
                $this->jsonResponse($response, ['error' => 'Bounty not available for claim']);
            }
            return;
        }

        // POST /api/broker/bounties/{id}/cancel
        if (preg_match('#^/api/broker/bounties/([a-f0-9-]+)/cancel$#', $uri, $m) && $method === 'POST') {
            $bountyId = $m[1];
            $cancelled = $this->cancelBounty($bountyId);
            if ($cancelled) {
                $this->jsonResponse($response, ['bounty' => $cancelled->toArray()]);
            } else {
                $response->status(404);
                $this->jsonResponse($response, ['error' => 'Cannot cancel bounty']);
            }
            return;
        }

        // GET /api/broker/capabilities
        if ($uri === '/api/broker/capabilities' && $method === 'GET') {
            $this->jsonResponse($response, [
                'capabilities' => array_map(
                    fn(CapabilityProfile $p) => $p->toArray(),
                    $this->bountyBoard->getCapabilities(),
                ),
            ]);
            return;
        }

        // GET /api/broker/peers
        if ($uri === '/api/broker/peers' && $method === 'GET') {
            $this->jsonResponse($response, ['peers' => array_values($this->brokerPeers)]);
            return;
        }

        // GET /api/broker/find?capabilities=a,b,c
        if ($uri === '/api/broker/find' && $method === 'GET') {
            $caps = isset($request->get['capabilities'])
                ? explode(',', $request->get['capabilities'])
                : [];
            $matches = $this->bountyBoard->findCapableNodes($caps);
            $this->jsonResponse($response, [
                'nodes' => array_map(fn(CapabilityProfile $p) => $p->toArray(), $matches),
            ]);
            return;
        }

        $response->status(404);
        $this->jsonResponse($response, ['error' => 'Unknown broker endpoint']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function jsonResponse(Response $response, array $data): void
    {
        $response->end(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    private function fireBountyUpdate(): void
    {
        if ($this->onBountyUpdate) {
            ($this->onBountyUpdate)();
        }
    }

    private function log(string $message): void
    {
        if ($this->logger) {
            ($this->logger)($message);
        }
    }
}
