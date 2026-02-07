<?php

declare(strict_types=1);

namespace VoidLux\Swarm;

use Swoole\Coroutine;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WsServer;
use VoidLux\P2P\Discovery\PeerExchange;
use VoidLux\P2P\Discovery\SeedPeers;
use VoidLux\P2P\Discovery\UdpBroadcast;
use VoidLux\P2P\PeerManager;
use VoidLux\P2P\Protocol\LamportClock;
use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\Connection;
use VoidLux\P2P\Transport\TcpMesh;
use VoidLux\Swarm\Agent\AgentBridge;
use VoidLux\Swarm\Agent\AgentMonitor;
use VoidLux\Swarm\Agent\AgentRegistry;
use VoidLux\Swarm\Gossip\TaskAntiEntropy;
use VoidLux\Swarm\Gossip\TaskGossipEngine;
use VoidLux\Swarm\Orchestrator\ClaimResolver;
use VoidLux\Swarm\Orchestrator\EmperorController;
use VoidLux\Swarm\Orchestrator\TaskQueue;
use VoidLux\Swarm\Storage\SwarmDatabase;

/**
 * Main swarm server combining HTTP/WebSocket + TCP mesh + UDP discovery.
 * Follows the GraffitiWall\Server pattern.
 */
class Server
{
    private string $nodeId;
    private SwarmDatabase $db;
    private LamportClock $clock;
    private TcpMesh $mesh;
    private PeerManager $peerManager;
    private PeerExchange $peerExchange;
    private UdpBroadcast $udpBroadcast;
    private TaskGossipEngine $taskGossip;
    private TaskAntiEntropy $taskAntiEntropy;
    private TaskQueue $taskQueue;
    private ClaimResolver $claimResolver;
    private AgentRegistry $agentRegistry;
    private AgentBridge $agentBridge;
    private AgentMonitor $agentMonitor;
    private EmperorController $controller;
    private ?SwarmWebSocketHandler $wsHandler = null;
    private float $startTime;

    public function __construct(
        private readonly string $httpHost = '0.0.0.0',
        private readonly int $httpPort = 9090,
        private readonly int $p2pPort = 7101,
        private readonly int $discoveryPort = 6101,
        private readonly array $seedPeers = [],
        private readonly string $dataDir = './data',
        private readonly string $role = 'emperor',
    ) {
        $this->startTime = microtime(true);
    }

    public function run(): void
    {
        $dbPath = $this->dataDir . "/swarm-{$this->p2pPort}.db";
        $this->db = new SwarmDatabase($dbPath);

        $this->nodeId = $this->db->getState('node_id');
        if (!$this->nodeId) {
            $this->nodeId = bin2hex(random_bytes(16));
            $this->db->setState('node_id', $this->nodeId);
        }

        $savedClock = (int) $this->db->getState('lamport_clock', '0');
        $this->clock = new LamportClock($savedClock);

        $this->log("Swarm Node ID: {$this->nodeId}");
        $this->log("Role: {$this->role} | HTTP: {$this->httpHost}:{$this->httpPort} | P2P: {$this->p2pPort} | Discovery: {$this->discoveryPort}");

        $server = new WsServer($this->httpHost, $this->httpPort);
        $server->set([
            'worker_num' => 1,
            'enable_coroutine' => true,
            'hook_flags' => SWOOLE_HOOK_ALL,
            'open_http2_protocol' => false,
        ]);

        $this->wsHandler = new SwarmWebSocketHandler($server);

        $server->on('start', function () {
            $this->log("Swarm server started");
        });

        $server->on('request', function (Request $request, Response $response) {
            $this->controller->handle($request, $response);
        });

        $server->on('open', function (WsServer $srv, Request $request) {
            $this->wsHandler->onOpen($request->fd);
        });

        $server->on('message', function (WsServer $srv, Frame $frame) {
            // Client WS messages can be handled here if needed
        });

        $server->on('close', function (WsServer $srv, int $fd) {
            $this->wsHandler->onClose($fd);
        });

        $server->on('workerStart', function () {
            $this->initComponents();
            $this->startP2P();
            $this->startSwarm();
        });

        $server->start();
    }

    private function initComponents(): void
    {
        $this->mesh = new TcpMesh('0.0.0.0', $this->p2pPort, $this->nodeId);
        $this->peerManager = new PeerManager($this->mesh, $this->nodeId);
        $this->taskGossip = new TaskGossipEngine($this->mesh, $this->db, $this->clock);
        $this->taskAntiEntropy = new TaskAntiEntropy($this->mesh, $this->db, $this->taskGossip);
        $this->taskQueue = new TaskQueue($this->db, $this->taskGossip, $this->clock, $this->nodeId);
        $this->claimResolver = new ClaimResolver($this->db, $this->nodeId);
        $this->agentBridge = new AgentBridge($this->db);
        $this->agentRegistry = new AgentRegistry($this->db, $this->taskGossip, $this->clock, $this->nodeId);
        $this->agentMonitor = new AgentMonitor($this->db, $this->agentBridge, $this->taskQueue, $this->agentRegistry, $this->nodeId);
        $this->peerExchange = new PeerExchange($this->mesh, $this->peerManager);

        $this->controller = new EmperorController(
            $this->db,
            $this->taskQueue,
            $this->agentRegistry,
            $this->agentBridge,
            $this->nodeId,
            $this->startTime,
        );

        // Wire agent monitor events to WebSocket
        $this->agentMonitor->onEvent(function (string $taskId, string $agentId, string $event, array $data) {
            $this->log("Event: {$event} task={$taskId} agent={$agentId}");
            $this->wsHandler?->pushTaskEvent($event, array_merge($data, [
                'task_id' => $taskId,
                'agent_id' => $agentId,
            ]));
        });
    }

    private function startP2P(): void
    {
        $this->mesh->onConnection(function (Connection $conn) {
            $conn->send([
                'type' => MessageTypes::HELLO,
                'node_id' => $this->nodeId,
                'p2p_port' => $this->p2pPort,
                'http_port' => $this->httpPort,
            ]);
        });

        $this->mesh->onMessage(function (Connection $conn, array $msg) {
            $this->onPeerMessage($conn, $msg);
        });

        $this->mesh->onDisconnect(function (Connection $conn) {
            $this->peerManager->unregisterPeer($conn);
            $this->log("Peer disconnected: {$conn->address()}");
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
    }

    private function startSwarm(): void
    {
        // Task anti-entropy
        Coroutine::create(function () {
            $this->taskAntiEntropy->start();
        });

        // Agent registry heartbeats
        Coroutine::create(function () {
            $this->agentRegistry->start();
        });

        // Agent monitor (polls busy agents)
        Coroutine::create(function () {
            $this->agentMonitor->start();
        });

        // Periodic status to WS + clock persistence
        Coroutine::create(function () {
            while (true) {
                Coroutine::sleep(5);
                $this->wsHandler?->pushStatus([
                    'tasks' => $this->db->getTaskCount(),
                    'agents' => $this->db->getAgentCount(),
                    'peers' => $this->peerManager->getPeerCount(),
                ]);
                $this->db->setState('lamport_clock', (string) $this->clock->value());
            }
        });

        $this->log("Swarm orchestration started (role: {$this->role})");
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

            // --- Swarm task messages ---
            case MessageTypes::TASK_CREATE:
                $task = $this->taskGossip->receiveTaskCreate($msg['task'] ?? [], $conn->address());
                if ($task) {
                    $this->log("Received task: {$task->id} '{$task->title}'");
                    $this->wsHandler?->pushTaskEvent('task_created', $task->toArray());
                }
                break;

            case MessageTypes::TASK_CLAIM:
                $isNew = $this->taskGossip->receiveTaskClaim($msg, $conn->address());
                if ($isNew) {
                    $this->claimResolver->resolveRemoteClaim(
                        $msg['task_id'] ?? '',
                        $msg['agent_id'] ?? '',
                        $msg['node_id'] ?? '',
                        $msg['lamport_ts'] ?? 0,
                    );
                    $this->wsHandler?->pushTaskEvent('task_claimed', $msg);
                }
                break;

            case MessageTypes::TASK_UPDATE:
                $isNew = $this->taskGossip->receiveTaskUpdate($msg, $conn->address());
                if ($isNew) {
                    $this->wsHandler?->pushTaskEvent('task_progress', $msg);
                }
                break;

            case MessageTypes::TASK_COMPLETE:
                $isNew = $this->taskGossip->receiveTaskComplete($msg, $conn->address());
                if ($isNew) {
                    $this->wsHandler?->pushTaskEvent('task_completed', $msg);
                }
                break;

            case MessageTypes::TASK_FAIL:
                $isNew = $this->taskGossip->receiveTaskFail($msg, $conn->address());
                if ($isNew) {
                    $this->wsHandler?->pushTaskEvent('task_failed', $msg);
                }
                break;

            case MessageTypes::TASK_CANCEL:
                $isNew = $this->taskGossip->receiveTaskCancel($msg, $conn->address());
                if ($isNew) {
                    $this->wsHandler?->pushTaskEvent('task_cancelled', $msg);
                }
                break;

            // --- Swarm agent messages ---
            case MessageTypes::AGENT_REGISTER:
                $agent = $this->taskGossip->receiveAgentRegister($msg, $conn->address());
                if ($agent) {
                    $this->log("Agent registered: {$agent->name} ({$agent->id})");
                    $this->wsHandler?->pushAgentEvent('agent_registered', $agent->toArray());
                }
                break;

            case MessageTypes::AGENT_HEARTBEAT:
                $this->taskGossip->receiveAgentHeartbeat($msg, $conn->address());
                break;

            // --- Swarm sync messages ---
            case MessageTypes::TASK_SYNC_REQ:
                $this->taskAntiEntropy->handleSyncRequest($conn, $msg);
                break;

            case MessageTypes::TASK_SYNC_RSP:
                $count = $this->taskAntiEntropy->handleSyncResponse($msg);
                if ($count > 0) {
                    $this->log("Synced {$count} tasks from {$conn->address()}");
                }
                break;
        }
    }

    private function log(string $message): void
    {
        $short = substr($this->nodeId, 0, 8);
        $time = date('H:i:s');
        echo "[{$time}][{$short}][swarm] {$message}\n";
    }
}
