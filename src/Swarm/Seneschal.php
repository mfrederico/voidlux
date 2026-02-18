<?php

declare(strict_types=1);

namespace VoidLux\Swarm;

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client as HttpClient;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WsServer;
use VoidLux\Swarm\Broker\BrokerMesh;
use VoidLux\P2P\Discovery\PeerExchange;
use VoidLux\P2P\Discovery\SeedPeers;
use VoidLux\P2P\Discovery\UdpBroadcast;
use VoidLux\P2P\PeerManager;
use VoidLux\P2P\Protocol\LamportClock;
use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\Connection;
use VoidLux\P2P\Transport\TcpMesh;
use VoidLux\Swarm\Upgrade\UpgradeCoordinator;
use VoidLux\Swarm\Upgrade\UpgradeDatabase;

/**
 * Stable reverse proxy that tracks the current emperor via P2P mesh
 * and forwards all HTTP/WebSocket traffic to it.
 *
 * On regicide + leader election, the Seneschal seamlessly re-routes
 * to the new emperor without the browser changing ports.
 */
class Seneschal
{
    private string $nodeId;
    private LamportClock $clock;
    private TcpMesh $mesh;
    private PeerManager $peerManager;
    private PeerExchange $peerExchange;
    private UdpBroadcast $udpBroadcast;
    private ?BrokerMesh $brokerMesh = null;
    private ?WsServer $server = null;

    private ?string $emperorNodeId = null;
    private string $emperorHost = '127.0.0.1';
    private int $emperorHttpPort = 0;

    /** @var array<int, HttpClient> browser fd => upstream WS client */
    private array $wsUpstreams = [];

    private ?UpgradeCoordinator $upgradeCoordinator = null;
    private ?UpgradeDatabase $upgradeDb = null;

    /** @var array<string, array{node_id: string, host: string, http_port: int, role: string}> */
    private array $knownPeers = [];

    public function __construct(
        private readonly string $httpHost = '0.0.0.0',
        private readonly int $httpPort = 9090,
        private readonly int $p2pPort = 7100,
        private readonly int $discoveryPort = 6101,
        private readonly array $seedPeers = [],
        private readonly string $dataDir = './data',
    ) {
        $this->nodeId = bin2hex(random_bytes(16));
        $this->clock = new LamportClock();
    }

    public function run(): void
    {
        $this->log("Seneschal Node ID: {$this->nodeId}");
        $this->log("HTTP: {$this->httpHost}:{$this->httpPort} | P2P: {$this->p2pPort} | Discovery: {$this->discoveryPort}");

        $this->server = new WsServer($this->httpHost, $this->httpPort);
        $server = $this->server;
        $server->set([
            'worker_num' => 1,
            'enable_coroutine' => true,
            'hook_flags' => SWOOLE_HOOK_ALL,
            'open_http2_protocol' => false,
        ]);

        $server->on('start', function () {
            $this->log("Seneschal proxy started on port {$this->httpPort}");
        });

        $server->on('request', function (Request $request, Response $response) {
            // Broker API endpoints handled locally (not proxied to emperor)
            $uri = $request->server['request_uri'] ?? '/';
            if ($this->brokerMesh && str_starts_with($uri, '/api/broker/')) {
                $this->brokerMesh->handleApi($request, $response);
                return;
            }
            $this->proxyHttp($request, $response);
        });

        $server->on('open', function (WsServer $srv, Request $request) {
            $this->proxyWsOpen($srv, $request);
        });

        $server->on('message', function (WsServer $srv, Frame $frame) {
            $this->proxyWsMessage($srv, $frame);
        });

        $server->on('close', function (WsServer $srv, int $fd) {
            $this->proxyWsClose($fd);
        });

        $server->on('workerStart', function () {
            $this->initP2P();
            $this->initBroker();
        });

        $server->start();
    }

    private function initP2P(): void
    {
        $this->mesh = new TcpMesh('0.0.0.0', $this->p2pPort, $this->nodeId);
        $this->peerManager = new PeerManager($this->mesh, $this->nodeId);
        $this->peerExchange = new PeerExchange($this->mesh, $this->peerManager);

        $this->mesh->onConnection(function (Connection $conn) {
            $conn->send([
                'type' => MessageTypes::HELLO,
                'node_id' => $this->nodeId,
                'p2p_port' => $this->p2pPort,
                'http_port' => $this->httpPort,
                'role' => 'seneschal',
            ]);
        });

        $this->mesh->onMessage(function (Connection $conn, array $msg) {
            $this->onPeerMessage($conn, $msg);
            // Relay marketplace messages to broker network if enabled
            $this->brokerMesh?->onSwarmMessage($msg['type'] ?? 0, $msg);
        });

        $this->mesh->onDisconnect(function (Connection $conn) {
            $nodeId = $conn->getPeerId();
            $this->peerManager->unregisterPeer($conn);
            $this->log("Peer disconnected: {$conn->address()}");

            // Remove from known peers (will be re-added on reconnect)
            if ($nodeId) {
                unset($this->knownPeers[$nodeId]);
            }

            // If the emperor's connection dropped, reset so we show the election page
            if ($nodeId && $nodeId === $this->emperorNodeId) {
                $this->log("Emperor connection lost — awaiting new leader");
                $this->emperorNodeId = null;
                $this->emperorHttpPort = 0;
            }
        });

        Coroutine::create(function () {
            $this->mesh->start();
        });

        Coroutine::create(function () {
            $this->peerManager->start();
        });

        Coroutine::create(function () {
            $this->peerExchange->start();
        });

        // UDP discovery
        $this->udpBroadcast = new UdpBroadcast($this->discoveryPort, $this->p2pPort, $this->nodeId);
        $this->udpBroadcast->onPeerDiscovered(function (string $host, int $port, string $nodeId) {
            if ($nodeId === $this->nodeId) {
                return; // Don't connect to ourselves
            }
            if (!$this->peerManager->isConnected($nodeId)) {
                $this->log("Discovered peer via UDP: {$host}:{$port}");
                $this->peerManager->addKnownAddress($host, $port);
                Coroutine::create(function () use ($host, $port) {
                    $this->mesh->connectTo($host, $port);
                });
            }
        });

        Coroutine::create(function () {
            $this->udpBroadcast->start();
        });

        // Seed peers
        $seeds = new SeedPeers($this->seedPeers);
        foreach ($seeds->getSeeds() as $seed) {
            $this->peerManager->addKnownAddress($seed['host'], $seed['port']);
            Coroutine::create(function () use ($seed) {
                $this->log("Connecting to seed peer: {$seed['host']}:{$seed['port']}");
                $this->mesh->connectTo($seed['host'], $seed['port']);
            });
        }

        $this->log("P2P started on port {$this->p2pPort}");

        // Initialize upgrade system
        $this->initUpgradeSystem();
    }

    private function initUpgradeSystem(): void
    {
        $dbPath = $this->dataDir . '/seneschal-upgrade.db';
        $this->upgradeDb = new UpgradeDatabase($dbPath);

        $projectDir = getcwd();
        $this->upgradeCoordinator = new UpgradeCoordinator(
            $this->mesh,
            $this->upgradeDb,
            $this->nodeId,
            $projectDir,
        );
        $this->upgradeCoordinator->onLog(function (string $msg) {
            $this->log($msg);
        });

        $this->log("Upgrade system initialized (db: {$dbPath})");
    }

    private function onPeerMessage(Connection $conn, array $msg): void
    {
        $type = $msg['type'] ?? 0;

        switch ($type) {
            case MessageTypes::HELLO:
                $nodeId = $msg['node_id'] ?? '';
                $p2pPort = $msg['p2p_port'] ?? $this->p2pPort;
                $httpPort = $msg['http_port'] ?? 0;
                $peerRole = $msg['role'] ?? 'worker';
                $this->peerManager->registerPeer($conn, $nodeId, $conn->remoteHost, $p2pPort);
                $this->log("Peer connected: {$nodeId} at {$conn->address()} (role: {$peerRole})");

                // Track all peers for upgrade coordination
                if ($nodeId && $peerRole !== 'seneschal') {
                    $this->knownPeers[$nodeId] = [
                        'node_id' => $nodeId,
                        'host' => $conn->remoteHost,
                        'http_port' => $httpPort,
                        'role' => $peerRole,
                    ];
                }

                if ($peerRole === 'emperor') {
                    $this->updateEmperor($nodeId, $conn->remoteHost, $httpPort);
                }
                break;

            case MessageTypes::PEX:
                $newPeers = $this->peerExchange->handlePex($msg);
                foreach ($newPeers as $peer) {
                    $this->peerManager->addKnownAddress($peer['host'], $peer['port']);
                }
                break;

            case MessageTypes::PING:
                $conn->send([
                    'type' => MessageTypes::PONG,
                    'node_id' => $this->nodeId,
                    'timestamp' => $msg['timestamp'] ?? 0,
                ]);
                break;

            case MessageTypes::PONG:
                break;

            case MessageTypes::EMPEROR_HEARTBEAT:
                $this->clock->witness($msg['lamport_ts'] ?? 0);
                $empNodeId = $msg['node_id'] ?? '';
                $empHttpPort = $msg['http_port'] ?? 0;
                $this->updateEmperor($empNodeId, $conn->remoteHost, $empHttpPort);

                // Keep emperor in known peers with current info
                if ($empNodeId) {
                    $this->knownPeers[$empNodeId] = [
                        'node_id' => $empNodeId,
                        'host' => $conn->remoteHost,
                        'http_port' => $empHttpPort,
                        'role' => 'emperor',
                    ];
                }
                break;

            case MessageTypes::ELECTION_VICTORY:
                $this->clock->witness($msg['lamport_ts'] ?? 0);
                $newNodeId = $msg['node_id'] ?? '';
                $newHttpPort = $msg['http_port'] ?? 0;
                $this->log("Election victory: new emperor {$newNodeId} (http:{$newHttpPort})");
                $this->updateEmperor($newNodeId, $conn->remoteHost, $newHttpPort);

                // Update known peers: promoted worker becomes emperor
                if ($newNodeId) {
                    $this->knownPeers[$newNodeId] = [
                        'node_id' => $newNodeId,
                        'host' => $conn->remoteHost,
                        'http_port' => $newHttpPort,
                        'role' => 'emperor',
                    ];
                }

                $this->reconnectUpstreamWebSockets();
                $this->requestCensus();
                break;

            case MessageTypes::UPGRADE_STATUS:
                $this->upgradeCoordinator?->handleUpgradeStatus($msg);
                break;

            // All other message types (task/agent gossip) are silently ignored
        }
    }

    private function updateEmperor(string $nodeId, string $host, int $httpPort): void
    {
        if (!$nodeId || !$httpPort) {
            return;
        }

        $changed = ($this->emperorNodeId !== $nodeId || $this->emperorHttpPort !== $httpPort);
        $this->emperorNodeId = $nodeId;
        $this->emperorHost = $host;
        $this->emperorHttpPort = $httpPort;

        if ($changed) {
            $short = substr($nodeId, 0, 8);
            $this->log("Emperor updated: {$short} at {$host}:{$httpPort}");
        }
    }

    // ── HTTP Reverse Proxy ──────────────────────────────────────────────

    private function proxyHttp(Request $request, Response $response): void
    {
        $path = $request->server['request_uri'] ?? '/';
        $method = $request->server['request_method'] ?? 'GET';

        // Seneschal-owned endpoints (not proxied to emperor)
        if ($this->handleLocalEndpoint($path, $method, $request, $response)) {
            return;
        }

        $isApi = str_starts_with($path, '/api/') || $path === '/mcp';

        if ($this->emperorHttpPort === 0) {
            $response->status(503);
            if ($isApi) {
                $response->header('Content-Type', 'application/json');
                $response->end(json_encode(['error' => 'No emperor available', 'electing' => true]));
            } else {
                $response->header('Content-Type', 'text/html; charset=utf-8');
                $response->end(self::electionPage());
            }
            return;
        }

        $client = new HttpClient($this->emperorHost, $this->emperorHttpPort);
        $client->set(['timeout' => 30]);

        // Copy request headers
        $headers = [];
        foreach ($request->header ?? [] as $key => $value) {
            $headers[$key] = $value;
        }
        $headers['x-forwarded-for'] = $request->server['remote_addr'] ?? '127.0.0.1';
        $headers['x-forwarded-host'] = $request->header['host'] ?? '';
        $client->setHeaders($headers);

        $uri = $request->server['request_uri'] ?? '/';
        if (isset($request->server['query_string']) && $request->server['query_string'] !== '') {
            $uri .= '?' . $request->server['query_string'];
        }

        $body = $request->getContent();

        $client->setMethod(strtoupper($method));
        if ($body !== false && $body !== '') {
            $client->setData($body);
        }

        $client->execute($uri);

        if ($client->errCode !== 0) {
            $response->status(502);
            if ($isApi) {
                $response->header('Content-Type', 'application/json');
                $response->end(json_encode(['error' => 'Emperor unreachable', 'electing' => true]));
            } else {
                $response->header('Content-Type', 'text/html; charset=utf-8');
                $response->end(self::electionPage());
            }
            $client->close();
            return;
        }

        // Copy upstream response
        $response->status($client->statusCode);
        foreach ($client->headers ?? [] as $key => $value) {
            $lower = strtolower($key);
            // Swoole's HttpClient decompresses gzip but keeps the header — strip it
            if (in_array($lower, ['transfer-encoding', 'connection', 'content-encoding', 'content-length'], true)) {
                continue;
            }
            $response->header($key, $value);
        }
        $response->end($client->body);
        $client->close();
    }

    // ── WebSocket Relay ─────────────────────────────────────────────────

    private function proxyWsOpen(WsServer $srv, Request $request): void
    {
        $fd = $request->fd;

        if ($this->emperorHttpPort === 0) {
            $srv->disconnect($fd, 1013, 'No emperor available');
            return;
        }

        Coroutine::create(function () use ($srv, $fd) {
            $upstream = $this->connectUpstreamWs();
            if ($upstream === null) {
                if ($srv->isEstablished($fd)) {
                    $srv->disconnect($fd, 1013, 'Cannot reach emperor');
                }
                return;
            }

            $this->wsUpstreams[$fd] = $upstream;

            // Upstream reader coroutine: relay emperor → browser
            while (true) {
                $frame = $upstream->recv(60.0);
                if ($frame === false || $frame === '') {
                    // Timeout (errCode 110) is normal — just keep waiting
                    if ($upstream->errCode === SWOOLE_ERROR_CO_TIMEDOUT) {
                        // Send a ping to keep the connection alive
                        if (!$upstream->push('', WEBSOCKET_OPCODE_PING)) {
                            break; // Upstream actually dead
                        }
                        continue;
                    }
                    break; // Real disconnect or error
                }
                if ($frame instanceof Frame) {
                    if ($frame->opcode === WEBSOCKET_OPCODE_CLOSE) {
                        break;
                    }
                    if ($frame->opcode === WEBSOCKET_OPCODE_PONG) {
                        continue; // Pong response, ignore
                    }
                    if ($srv->isEstablished($fd)) {
                        $srv->push($fd, $frame->data, $frame->opcode);
                    }
                }
            }

            // Upstream disconnected — close browser side
            unset($this->wsUpstreams[$fd]);
            $upstream->close();
            if ($srv->isEstablished($fd)) {
                $srv->disconnect($fd, 1001, 'Upstream closed');
            }
        });
    }

    private function proxyWsMessage(WsServer $srv, Frame $frame): void
    {
        $upstream = $this->wsUpstreams[$frame->fd] ?? null;
        if ($upstream === null) {
            return;
        }

        $upstream->push($frame->data, $frame->opcode);
    }

    private function proxyWsClose(int $fd): void
    {
        $upstream = $this->wsUpstreams[$fd] ?? null;
        if ($upstream !== null) {
            unset($this->wsUpstreams[$fd]);
            $upstream->close();
        }
    }

    private function connectUpstreamWs(): ?HttpClient
    {
        $client = new HttpClient($this->emperorHost, $this->emperorHttpPort);
        $client->set(['timeout' => 10]);

        $upgraded = $client->upgrade('/ws');
        if (!$upgraded) {
            $client->close();
            return null;
        }

        return $client;
    }

    private function requestCensus(): void
    {
        // Short delay for new emperor to stabilize before census
        Coroutine::create(function () {
            Coroutine::sleep(3);
            $this->log("Broadcasting census request to all peers");
            $this->mesh->broadcast([
                'type' => MessageTypes::CENSUS_REQUEST,
                'node_id' => $this->nodeId,
            ]);
        });
    }

    private function reconnectUpstreamWebSockets(): void
    {
        // Close all existing upstream connections.
        // Each browser's upstream reader coroutine will detect the close,
        // which triggers disconnect on the browser side.
        // The browser JS auto-reconnects, and the new connection will
        // route to the new emperor.
        foreach ($this->wsUpstreams as $fd => $upstream) {
            $upstream->close();
        }
        $this->wsUpstreams = [];
        $this->log("Closed all upstream WS connections for emperor switchover");
    }

    // ── Seneschal-Owned API Endpoints ──────────────────────────────────

    /**
     * Handle endpoints owned by the Seneschal (not proxied to emperor).
     * Returns true if the request was handled locally.
     */
    private function handleLocalEndpoint(string $path, string $method, Request $request, Response $response): bool
    {
        $response->header('Access-Control-Allow-Origin', '*');
        $response->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->header('Access-Control-Allow-Headers', 'Content-Type');

        if ($method === 'OPTIONS' && str_starts_with($path, '/api/swarm/upgrade')) {
            $response->status(204);
            $response->end('');
            return true;
        }

        if ($path === '/api/swarm/upgrade' && $method === 'POST') {
            $this->handleUpgrade($request, $response);
            return true;
        }

        if ($path === '/api/swarm/upgrade/history' && $method === 'GET') {
            $this->handleUpgradeHistory($response);
            return true;
        }

        if ($path === '/api/swarm/upgrade/status' && $method === 'GET') {
            $this->handleUpgradeStatus($response);
            return true;
        }

        return false;
    }

    private function handleUpgrade(Request $request, Response $response): void
    {
        if (!$this->upgradeCoordinator || !$this->upgradeDb) {
            $response->status(503);
            $this->jsonResponse($response, ['error' => 'Upgrade system not initialized']);
            return;
        }

        if ($this->upgradeCoordinator->isUpgradeInProgress()) {
            $response->status(409);
            $this->jsonResponse($response, ['error' => 'Upgrade already in progress']);
            return;
        }

        $body = json_decode($request->getContent() ?: '{}', true) ?? [];
        $targetCommit = $body['target_commit'] ?? $body['commit'] ?? $body['ref'] ?? '';

        // Separate workers from emperor
        $workers = [];
        foreach ($this->knownPeers as $peer) {
            if ($peer['role'] === 'worker' && $peer['http_port'] > 0) {
                $workers[] = $peer;
            }
        }

        if (!$this->emperorNodeId || $this->emperorHttpPort === 0) {
            $response->status(503);
            $this->jsonResponse($response, ['error' => 'No emperor available — cannot coordinate upgrade']);
            return;
        }

        $this->log("Upgrade requested: target={$targetCommit}, workers=" . count($workers));

        // Respond immediately, run upgrade in background coroutine
        $upgradeId = substr(bin2hex(random_bytes(8)), 0, 16);
        $response->status(202);
        $this->jsonResponse($response, [
            'status' => 'accepted',
            'upgrade_id' => $upgradeId,
            'target_commit' => $targetCommit ?: '(latest)',
            'workers' => count($workers),
            'message' => 'Rolling upgrade initiated. Check /api/swarm/upgrade/status for progress.',
        ]);

        // Run the upgrade asynchronously
        $coordinator = $this->upgradeCoordinator;
        $empHost = $this->emperorHost;
        $empPort = $this->emperorHttpPort;

        Coroutine::create(function () use ($coordinator, $targetCommit, $empHost, $empPort, $workers) {
            $result = $coordinator->startUpgrade($targetCommit, $empHost, $empPort, $workers);
            $this->log("Upgrade finished: {$result->status} (updated: {$result->nodesUpdated}, rolled back: {$result->nodesRolledBack})");
        });
    }

    private function handleUpgradeHistory(Response $response): void
    {
        if (!$this->upgradeDb) {
            $response->status(503);
            $this->jsonResponse($response, ['error' => 'Upgrade system not initialized']);
            return;
        }

        $history = $this->upgradeDb->getAll();
        $this->jsonResponse($response, [
            'history' => array_map(fn($h) => $h->toArray(), $history),
            'count' => count($history),
        ]);
    }

    private function handleUpgradeStatus(Response $response): void
    {
        if (!$this->upgradeCoordinator || !$this->upgradeDb) {
            $response->status(503);
            $this->jsonResponse($response, ['error' => 'Upgrade system not initialized']);
            return;
        }

        $latest = $this->upgradeDb->getLatest();
        $this->jsonResponse($response, [
            'in_progress' => $this->upgradeCoordinator->isUpgradeInProgress(),
            'latest' => $latest?->toArray(),
            'known_peers' => count($this->knownPeers),
            'workers' => count(array_filter($this->knownPeers, fn($p) => $p['role'] === 'worker')),
        ]);
    }

    private function jsonResponse(Response $response, array $data): void
    {
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    private static function electionPage(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VoidLux — Conclave in Session</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    background: #0a0a0a;
    color: #e0e0e0;
    font-family: 'Courier New', monospace;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}
.smoke {
    position: fixed; inset: 0; z-index: 0;
    background:
        radial-gradient(ellipse at 20% 50%, rgba(40,20,0,0.3) 0%, transparent 60%),
        radial-gradient(ellipse at 80% 50%, rgba(40,20,0,0.2) 0%, transparent 60%),
        radial-gradient(ellipse at 50% 100%, rgba(60,30,0,0.15) 0%, transparent 50%);
    animation: smokeShift 8s ease-in-out infinite alternate;
}
@keyframes smokeShift {
    0% { opacity: 0.6; transform: scale(1); }
    100% { opacity: 1; transform: scale(1.05); }
}
.container {
    position: relative; z-index: 1;
    text-align: center; max-width: 600px; padding: 40px;
}
.doors {
    font-size: 4rem;
    margin-bottom: 24px;
    animation: doorPulse 3s ease-in-out infinite;
    filter: drop-shadow(0 0 20px rgba(204,102,0,0.4));
}
@keyframes doorPulse {
    0%, 100% { opacity: 0.7; transform: scale(1); }
    50% { opacity: 1; transform: scale(1.05); }
}
h1 {
    font-size: 1.6rem;
    letter-spacing: 4px;
    text-transform: uppercase;
    margin-bottom: 16px;
    background: linear-gradient(90deg, #cc6600, #ffcc00, #cc6600);
    background-size: 200% auto;
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    animation: shimmer 3s linear infinite;
}
@keyframes shimmer { to { background-position: 200% center; } }
.subtitle {
    color: #886644;
    font-size: 0.95rem;
    line-height: 1.6;
    margin-bottom: 32px;
}
.status-line {
    color: #666;
    font-size: 0.8rem;
    margin-bottom: 8px;
}
.dots {
    display: inline-block;
    animation: dots 2s steps(4, end) infinite;
}
.dots::after { content: ''; }
@keyframes dots {
    0% { content: ''; }
    25% { content: '.'; }
    50% { content: '..'; }
    75% { content: '...'; }
}
@keyframes dots { 0%,20% { content:''; } 40% { content:'.'; } 60% { content:'..'; } 80%,100% { content:'...'; } }
.candle {
    display: inline-block;
    font-size: 1.2rem;
    animation: flicker 0.5s ease-in-out infinite alternate;
    margin: 0 4px;
}
.candle:nth-child(2) { animation-delay: 0.15s; }
.candle:nth-child(3) { animation-delay: 0.3s; }
@keyframes flicker {
    0% { opacity: 0.6; transform: translateY(0); }
    100% { opacity: 1; transform: translateY(-2px); }
}
.bottom-line {
    position: fixed; bottom: 24px; left: 0; right: 0;
    text-align: center; color: #444; font-size: 0.7rem; z-index: 1;
}
</style>
</head>
<body>
<div class="smoke"></div>
<div class="container">
    <div class="doors">&#x1FA84;</div>
    <h1>The Emperor Has No Clothes</h1>
    <div class="subtitle">
        The emperor has been exposed and deposed.<br>
        Behind closed doors, the nodes deliberate.<br>
        A new leader will emerge from the conclave.
    </div>
    <div class="status-line">
        <span class="candle">&#x1F56F;</span>
        <span class="candle">&#x1F56F;</span>
        <span class="candle">&#x1F56F;</span>
    </div>
    <div class="status-line" style="margin-top:16px;">
        Electing new emperor<span class="dots"></span>
    </div>
</div>
<div class="bottom-line">
    This page will auto-refresh when the new emperor is crowned.
</div>
<script>
setInterval(() => {
    fetch('/api/swarm/status').then(r => {
        if (r.ok) location.reload();
    }).catch(() => {});
}, 3000);
</script>
</body>
</html>
HTML;
    }

    // ── Broker Mesh (Seneschal-to-Seneschal) ──────────────────────────

    /**
     * Initialize the cross-swarm broker mesh if configured via env vars.
     * VOIDLUX_BROKER_PORT  — TCP port for broker connections (0 = disabled)
     * VOIDLUX_BROKER_SEEDS — Comma-separated host:port list of broker peers
     * VOIDLUX_SWARM_NAME   — Human-readable name for this swarm
     */
    private function initBroker(): void
    {
        $brokerPort = (int) (getenv('VOIDLUX_BROKER_PORT') ?: 0);
        if ($brokerPort <= 0) {
            return;
        }

        $brokerSeeds = getenv('VOIDLUX_BROKER_SEEDS') ?: '';
        $swarmName = getenv('VOIDLUX_SWARM_NAME') ?: ('swarm-' . substr($this->nodeId, 0, 8));
        $seeds = $brokerSeeds ? explode(',', $brokerSeeds) : [];

        $this->brokerMesh = new BrokerMesh(
            nodeId: $this->nodeId,
            brokerPort: $brokerPort,
            clock: $this->clock,
            brokerSeeds: $seeds,
            swarmName: $swarmName,
            localEmperorNodeId: $this->emperorNodeId ?? '',
            logger: fn(string $msg) => $this->log("[broker] {$msg}"),
        );

        $this->brokerMesh->onBountyUpdate(function () {
            $this->log("[broker] Bounty board updated");
        });

        Coroutine::create(function () {
            $this->brokerMesh->start();
        });

        $this->log("Broker mesh started on port {$brokerPort} (swarm: {$swarmName})");
        if (!empty($seeds)) {
            $this->log("Broker seeds: " . implode(', ', $seeds));
        }
    }

    private function log(string $message): void
    {
        $short = substr($this->nodeId, 0, 8);
        $time = date('H:i:s');
        echo "[{$time}][{$short}][seneschal] {$message}\n";
    }
}
