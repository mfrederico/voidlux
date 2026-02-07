<?php

declare(strict_types=1);

namespace VoidLux\App\GraffitiWall;

use Swoole\Coroutine;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WsServer;
use VoidLux\P2P\Discovery\PeerExchange;
use VoidLux\P2P\Discovery\SeedPeers;
use VoidLux\P2P\Discovery\UdpBroadcast;
use VoidLux\P2P\Gossip\AntiEntropy;
use VoidLux\P2P\Gossip\GossipEngine;
use VoidLux\P2P\PeerManager;
use VoidLux\P2P\Protocol\LamportClock;
use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\Connection;
use VoidLux\P2P\Transport\TcpMesh;

/**
 * Main graffiti wall server.
 * Combines HTTP/WebSocket server + TCP mesh + UDP discovery.
 */
class Server
{
    private string $nodeId;
    private Database $db;
    private LamportClock $clock;
    private TcpMesh $mesh;
    private PeerManager $peerManager;
    private GossipEngine $gossip;
    private AntiEntropy $antiEntropy;
    private PeerExchange $peerExchange;
    private UdpBroadcast $udpBroadcast;
    private ?WebSocketHandler $wsHandler = null;
    private float $startTime;

    public function __construct(
        private readonly string $httpHost = '0.0.0.0',
        private readonly int $httpPort = 8080,
        private readonly int $p2pPort = 7001,
        private readonly int $discoveryPort = 6001,
        private readonly array $seedPeers = [],
        private readonly string $dataDir = './data',
    ) {
        $this->startTime = microtime(true);
    }

    public function run(): void
    {
        // Generate or load node ID
        $dbPath = $this->dataDir . "/node-{$this->p2pPort}.db";
        $this->db = new Database($dbPath);

        $this->nodeId = $this->db->getState('node_id');
        if (!$this->nodeId) {
            $this->nodeId = bin2hex(random_bytes(16));
            $this->db->setState('node_id', $this->nodeId);
        }

        // Restore lamport clock
        $savedClock = (int) $this->db->getState('lamport_clock', '0');
        $this->clock = new LamportClock($savedClock);

        $this->log("Node ID: {$this->nodeId}");
        $this->log("HTTP: {$this->httpHost}:{$this->httpPort} | P2P: {$this->p2pPort} | Discovery: {$this->discoveryPort}");

        // Create Swoole WebSocket server (extends HTTP server)
        $server = new WsServer($this->httpHost, $this->httpPort);
        $server->set([
            'worker_num' => 1,
            'enable_coroutine' => true,
            'hook_flags' => SWOOLE_HOOK_ALL,
            'open_http2_protocol' => false,
        ]);

        $this->wsHandler = new WebSocketHandler($server);

        $server->on('start', function () {
            $this->onStart();
        });

        $server->on('request', function (Request $request, Response $response) {
            $this->onRequest($request, $response);
        });

        $server->on('open', function (WsServer $srv, Request $request) {
            $this->wsHandler->onOpen($request->fd);
        });

        $server->on('message', function (WsServer $srv, Frame $frame) {
            // Client messages not needed for now
        });

        $server->on('close', function (WsServer $srv, int $fd) {
            $this->wsHandler->onClose($fd);
        });

        $server->on('workerStart', function () {
            $this->startP2P();
        });

        $server->start();
    }

    private function onStart(): void
    {
        $this->log("Server started");
    }

    private function startP2P(): void
    {
        // TCP mesh for peer-to-peer
        $this->mesh = new TcpMesh('0.0.0.0', $this->p2pPort, $this->nodeId);
        $this->peerManager = new PeerManager($this->mesh, $this->nodeId);
        $this->gossip = new GossipEngine($this->mesh, $this->db, $this->clock);
        $this->antiEntropy = new AntiEntropy($this->mesh, $this->db, $this->gossip);
        $this->peerExchange = new PeerExchange($this->mesh, $this->peerManager);

        // Wire up mesh callbacks
        $this->mesh->onConnection(function (Connection $conn) {
            $this->onPeerConnect($conn);
        });

        $this->mesh->onMessage(function (Connection $conn, array $msg) {
            $this->onPeerMessage($conn, $msg);
        });

        $this->mesh->onDisconnect(function (Connection $conn) {
            $this->peerManager->unregisterPeer($conn);
            $this->log("Peer disconnected: {$conn->address()}");
        });

        // Start mesh
        Coroutine::create(function () {
            $this->mesh->start();
        });

        // Start peer manager
        Coroutine::create(function () {
            $this->peerManager->start();
        });

        // Start anti-entropy
        Coroutine::create(function () {
            $this->antiEntropy->start();
        });

        // Start peer exchange
        Coroutine::create(function () {
            $this->peerExchange->start();
        });

        // UDP discovery
        $this->udpBroadcast = new UdpBroadcast($this->discoveryPort, $this->p2pPort, $this->nodeId);
        $this->udpBroadcast->onPeerDiscovered(function (string $host, int $port, string $nodeId) {
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

        // Connect to seed peers
        $seeds = new SeedPeers($this->seedPeers);
        foreach ($seeds->getSeeds() as $seed) {
            $this->peerManager->addKnownAddress($seed['host'], $seed['port']);
            Coroutine::create(function () use ($seed) {
                $this->log("Connecting to seed peer: {$seed['host']}:{$seed['port']}");
                $this->mesh->connectTo($seed['host'], $seed['port']);
            });
        }

        // Periodic status broadcast to WS clients
        Coroutine::create(function () {
            while (true) {
                Coroutine::sleep(5);
                $this->wsHandler?->pushStatus([
                    'peers' => $this->peerManager->getPeerCount(),
                    'posts' => $this->db->getPostCount(),
                ]);
                // Persist clock
                $this->db->setState('lamport_clock', (string) $this->clock->value());
            }
        });

        $this->log("P2P started on port {$this->p2pPort}");
    }

    private function onPeerConnect(Connection $conn): void
    {
        // Send HELLO
        $conn->send([
            'type' => MessageTypes::HELLO,
            'node_id' => $this->nodeId,
            'p2p_port' => $this->p2pPort,
            'http_port' => $this->httpPort,
        ]);
    }

    private function onPeerMessage(Connection $conn, array $msg): void
    {
        $type = $msg['type'] ?? 0;

        switch ($type) {
            case MessageTypes::HELLO:
                $nodeId = $msg['node_id'] ?? '';
                $p2pPort = $msg['p2p_port'] ?? $this->p2pPort;
                $this->peerManager->registerPeer($conn, $nodeId, $conn->remoteHost, $p2pPort);
                $this->log("Peer connected: {$nodeId} at {$conn->address()}");
                break;

            case MessageTypes::POST:
                $post = $this->gossip->receivePost($msg['post'] ?? [], $conn->address());
                if ($post) {
                    $this->log("Received post: {$post->id} from {$post->author}");
                    $this->wsHandler?->pushPost($post);
                }
                break;

            case MessageTypes::SYNC_REQ:
                $this->antiEntropy->handleSyncRequest($conn, $msg);
                break;

            case MessageTypes::SYNC_RSP:
                $count = $this->antiEntropy->handleSyncResponse($msg);
                if ($count > 0) {
                    $this->log("Synced {$count} posts from {$conn->address()}");
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
                // Keepalive received, connection is alive
                break;
        }
    }

    private function onRequest(Request $request, Response $response): void
    {
        $path = $request->server['request_uri'] ?? '/';
        $method = $request->server['request_method'] ?? 'GET';

        // CORS headers for development
        $response->header('Access-Control-Allow-Origin', '*');
        $response->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->header('Access-Control-Allow-Headers', 'Content-Type');

        if ($method === 'OPTIONS') {
            $response->status(204);
            $response->end('');
            return;
        }

        switch (true) {
            case $path === '/' && $method === 'GET':
                $response->header('Content-Type', 'text/html; charset=utf-8');
                $response->end(WebUI::render($this->nodeId, $this->httpPort));
                break;

            case $path === '/health' && $method === 'GET':
                $this->jsonResponse($response, [
                    'status' => 'ok',
                    'uptime' => round(microtime(true) - $this->startTime, 1),
                    'node_id' => $this->nodeId,
                    'posts' => $this->db->getPostCount(),
                    'peers' => $this->peerManager->getPeerCount(),
                ]);
                break;

            case $path === '/api/posts' && $method === 'GET':
                $posts = $this->db->getAllPosts();
                $this->jsonResponse($response, array_map(fn($p) => $p->toArray(), $posts));
                break;

            case $path === '/api/posts' && $method === 'POST':
                $body = json_decode($request->getContent(), true);
                $content = trim($body['content'] ?? '');
                $author = trim($body['author'] ?? 'anon');

                if (!$content) {
                    $response->status(400);
                    $this->jsonResponse($response, ['error' => 'Content is required']);
                    break;
                }

                if (strlen($content) > 1000) {
                    $response->status(400);
                    $this->jsonResponse($response, ['error' => 'Content too long (max 1000 chars)']);
                    break;
                }

                $post = $this->gossip->createPost($content, $author, $this->nodeId);
                $this->wsHandler?->pushPost($post);
                $this->log("New post: {$post->id} by {$author}");

                $response->status(201);
                $this->jsonResponse($response, $post->toArray());
                break;

            case $path === '/api/peers' && $method === 'GET':
                $peers = [];
                foreach ($this->peerManager->getConnectedPeers() as $info) {
                    $peers[] = [
                        'node_id' => $info['node_id'],
                        'host' => $info['host'],
                        'port' => $info['port'],
                    ];
                }
                $this->jsonResponse($response, $peers);
                break;

            case $path === '/api/status' && $method === 'GET':
                $this->jsonResponse($response, [
                    'node_id' => $this->nodeId,
                    'uptime' => round(microtime(true) - $this->startTime, 1),
                    'posts' => $this->db->getPostCount(),
                    'peers' => $this->peerManager->getPeerCount(),
                    'lamport_clock' => $this->clock->value(),
                    'http_port' => $this->httpPort,
                    'p2p_port' => $this->p2pPort,
                    'ws_clients' => $this->wsHandler?->getConnectionCount() ?? 0,
                ]);
                break;

            default:
                $response->status(404);
                $this->jsonResponse($response, ['error' => 'Not found']);
                break;
        }
    }

    private function jsonResponse(Response $response, array $data): void
    {
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    private function log(string $message): void
    {
        $short = substr($this->nodeId, 0, 8);
        $time = date('H:i:s');
        echo "[{$time}][{$short}] {$message}\n";
    }
}
