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
use VoidLux\Swarm\Ai\LlmClient;
use VoidLux\Swarm\Ai\TaskPlanner;
use VoidLux\Swarm\Ai\TaskReviewer;
use VoidLux\Swarm\Gossip\AgentAntiEntropy;
use VoidLux\Swarm\Gossip\TaskAntiEntropy;
use VoidLux\Swarm\Gossip\TaskGossipEngine;
use VoidLux\Swarm\Leadership\LeaderElection;
use VoidLux\Swarm\Orchestrator\ClaimResolver;
use VoidLux\Swarm\Orchestrator\EmperorController;
use VoidLux\Swarm\Orchestrator\TaskDispatcher;
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
    private AgentAntiEntropy $agentAntiEntropy;
    private TaskQueue $taskQueue;
    private ClaimResolver $claimResolver;
    private AgentRegistry $agentRegistry;
    private AgentBridge $agentBridge;
    private AgentMonitor $agentMonitor;
    private EmperorController $controller;
    private LeaderElection $leaderElection;
    private ?TaskDispatcher $taskDispatcher = null;
    private ?SwarmWebSocketHandler $wsHandler = null;
    private ?WsServer $server = null;
    private float $startTime;
    private bool $running = false;

    public function __construct(
        private readonly string $httpHost = '0.0.0.0',
        private readonly int $httpPort = 9090,
        private readonly int $p2pPort = 7101,
        private readonly int $discoveryPort = 6101,
        private readonly array $seedPeers = [],
        private readonly string $dataDir = './data',
        private string $role = 'emperor',
        private readonly string $llmProvider = 'ollama',
        private readonly string $llmModel = 'qwen3:32b',
        private readonly string $llmHost = '127.0.0.1',
        private readonly int $llmPort = 11434,
        private readonly string $claudeApiKey = '',
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

        $this->server = new WsServer($this->httpHost, $this->httpPort);
        $server = $this->server;
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
        $this->agentAntiEntropy = new AgentAntiEntropy($this->mesh, $this->db, $this->nodeId);
        $this->taskQueue = new TaskQueue($this->db, $this->taskGossip, $this->clock, $this->nodeId);
        $this->claimResolver = new ClaimResolver($this->db, $this->nodeId);
        $this->agentBridge = new AgentBridge($this->db);
        $this->agentRegistry = new AgentRegistry($this->db, $this->taskGossip, $this->clock, $this->nodeId);
        $this->agentRegistry->setTaskQueue($this->taskQueue);
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
        $this->controller->setAgentMonitor($this->agentMonitor);
        $this->controller->onShutdown(function () {
            $this->log("Regicide: stopping all coroutine loops...");
            $this->running = false;
            $this->taskDispatcher?->stop();
            $this->agentMonitor->stop();
            $this->agentRegistry->stop();
            $this->leaderElection->stop();
            $this->taskAntiEntropy->stop();
            $this->agentAntiEntropy->stop();
            $this->peerExchange->stop();
            $this->peerManager->stop();
            $this->udpBroadcast->stop();
            $this->mesh->stop();
            $this->log("Regicide: shutting down server");
            $this->server?->shutdown();
        });

        // Wire leader election into agent monitor for pull-mode fallback
        // (set after leaderElection is created below)

        $this->leaderElection = new LeaderElection(
            $this->mesh,
            $this->peerManager,
            $this->clock,
            $this->nodeId,
            $this->httpPort,
            $this->p2pPort,
            $this->role,
        );
        $this->leaderElection->onPromoted(function (string $nodeId, int $httpPort, int $p2pPort) {
            $this->promote();
        });
        $this->leaderElection->onLog(function (string $msg) {
            $this->log($msg);
        });

        $this->agentMonitor->setLeaderElection($this->leaderElection);

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
                'role' => $this->role,
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
    }

    private function startSwarm(): void
    {
        // Requeue orphaned tasks from previous runs
        $orphaned = $this->db->getOrphanedTasks($this->nodeId);
        foreach ($orphaned as $task) {
            $this->taskQueue->requeue($task->id, 'Requeued on node restart');
            $this->log("Requeued orphaned task: {$task->id} '{$task->title}'");
        }
        if (count($orphaned) > 0) {
            $this->log("Requeued " . count($orphaned) . " orphaned task(s) from previous run");
        }

        // Startup wellness check — prune stale agents from previous runs
        $wellness = $this->agentMonitor->wellnessCheck();
        if (count($wellness['pruned']) > 0) {
            $this->log("Wellness check pruned " . count($wellness['pruned']) . " dead agent(s)");
        }
        $this->log("Wellness: " . count($wellness['alive']) . " alive, " . count($wellness['pruned']) . " pruned");

        // Emperor-only: AI components + push dispatcher
        if ($this->role === 'emperor') {
            $this->initEmperorAi();
        }

        // Task anti-entropy
        Coroutine::create(function () {
            $this->taskAntiEntropy->start();
        });

        // Agent anti-entropy
        Coroutine::create(function () {
            $this->agentAntiEntropy->start();
        });

        // Agent registry heartbeats
        Coroutine::create(function () {
            $this->agentRegistry->start();
        });

        // Agent monitor (polls busy agents)
        Coroutine::create(function () {
            $this->agentMonitor->start();
        });

        // Leader election (heartbeats + failover)
        Coroutine::create(function () {
            $this->leaderElection->start();
        });

        // Periodic status to WS + clock persistence
        $this->running = true;
        Coroutine::create(function () {
            while ($this->running) {
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
                $peerRole = $msg['role'] ?? 'worker';
                $this->peerManager->registerPeer($conn, $nodeId, $conn->remoteHost, $p2pPort);
                $this->log("Peer connected: {$nodeId} at {$conn->address()} (role: {$peerRole})");

                // If the connecting peer is the emperor, update election state
                if ($peerRole === 'emperor') {
                    $this->leaderElection->handleHeartbeat([
                        'node_id' => $nodeId,
                        'http_port' => $msg['http_port'] ?? 0,
                        'p2p_port' => $p2pPort,
                        'lamport_ts' => $this->clock->value(),
                    ]);
                }

                // Eager agent sync on every new peer connection
                if ($peerRole !== 'seneschal') {
                    $this->agentAntiEntropy->syncFromPeer($conn);
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

            // --- Swarm task messages ---
            case MessageTypes::TASK_CREATE:
                $task = $this->taskGossip->receiveTaskCreate($msg['task'] ?? [], $conn->address());
                if ($task) {
                    $this->log("Received task: {$task->id} '{$task->title}'");
                    $this->wsHandler?->pushTaskEvent('task_created', $task->toArray());
                    $this->taskDispatcher?->triggerDispatch();
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
                    if (($msg['status'] ?? '') === 'pending') {
                        $this->taskDispatcher?->triggerDispatch();
                    }
                }
                break;

            case MessageTypes::TASK_COMPLETE:
                $isNew = $this->taskGossip->receiveTaskComplete($msg, $conn->address());
                if ($isNew) {
                    $this->wsHandler?->pushTaskEvent('task_completed', $msg);
                    $this->taskDispatcher?->triggerDispatch();
                }
                break;

            case MessageTypes::TASK_FAIL:
                $isNew = $this->taskGossip->receiveTaskFail($msg, $conn->address());
                if ($isNew) {
                    $this->wsHandler?->pushTaskEvent('task_failed', $msg);
                    $this->taskDispatcher?->triggerDispatch();
                }
                break;

            case MessageTypes::TASK_CANCEL:
                $isNew = $this->taskGossip->receiveTaskCancel($msg, $conn->address());
                if ($isNew) {
                    $this->wsHandler?->pushTaskEvent('task_cancelled', $msg);
                }
                break;

            case MessageTypes::TASK_ASSIGN:
                $this->handleTaskAssign($msg);
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

            case MessageTypes::AGENT_DEREGISTER:
                $removedId = $this->taskGossip->receiveAgentDeregister($msg, $conn->address());
                if ($removedId) {
                    $this->log("Agent deregistered: {$removedId}");
                    $this->wsHandler?->pushAgentEvent('agent_deregistered', ['agent_id' => $removedId]);
                }
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

            // --- Leader election messages ---
            case MessageTypes::EMPEROR_HEARTBEAT:
                $this->leaderElection->handleHeartbeat($msg);
                break;

            case MessageTypes::ELECTION_START:
                $this->leaderElection->handleElectionStart($msg);
                break;

            case MessageTypes::ELECTION_VICTORY:
                $this->leaderElection->handleElectionVictory($msg);
                $this->wsHandler?->pushStatus([
                    'emperor' => $msg['node_id'] ?? '',
                    'emperor_http_port' => $msg['http_port'] ?? 0,
                ]);
                break;

            case MessageTypes::CENSUS_REQUEST:
                $count = $this->agentRegistry->reannounceAll();
                if ($count > 0) {
                    $this->log("Census: re-announced {$count} local agent(s)");
                }
                break;

            // --- Agent anti-entropy ---
            case MessageTypes::AGENT_SYNC_REQ:
                $this->agentAntiEntropy->handleSyncRequest($conn, $msg);
                break;

            case MessageTypes::AGENT_SYNC_RSP:
                $count = $this->agentAntiEntropy->handleSyncResponse($msg);
                if ($count > 0) {
                    $this->log("Synced {$count} agent(s) from {$conn->address()}");
                }
                break;
        }
    }

    private function promote(): void
    {
        $this->role = 'emperor';
        $this->leaderElection->setRole('emperor');
        $this->log("Promoted to emperor role");

        // Initialize emperor AI + dispatcher on promotion
        $this->initEmperorAi();

        $this->wsHandler?->pushStatus([
            'emperor' => $this->nodeId,
            'emperor_http_port' => $this->httpPort,
            'promoted' => true,
        ]);

        // Spawn a replacement worker process
        $this->spawnReplacementWorker();
    }

    private function initEmperorAi(): void
    {
        // Create LLM client
        $llm = new LlmClient(
            provider: $this->llmProvider,
            model: $this->llmModel,
            ollamaHost: $this->llmHost,
            ollamaPort: $this->llmPort,
            claudeApiKey: $this->claudeApiKey,
        );

        // Create AI components
        $planner = new TaskPlanner($llm, $this->db);
        $reviewer = new TaskReviewer($llm);

        // Create push dispatcher
        $this->taskDispatcher = new TaskDispatcher(
            $this->db,
            $this->mesh,
            $this->taskQueue,
            $this->clock,
            $this->nodeId,
        );

        // Wire into controller and task queue
        $this->controller->setTaskDispatcher($this->taskDispatcher);
        $this->controller->setTaskPlanner($planner);
        $this->taskQueue->setReviewer($reviewer);

        // Start dispatcher coroutine
        Coroutine::create(function () {
            $this->taskDispatcher->start();
        });

        $this->log("Emperor AI initialized (LLM: {$this->llmProvider}/{$this->llmModel})");
    }

    /**
     * Handle TASK_ASSIGN from emperor: claim task and deliver to local agent.
     */
    private function handleTaskAssign(array $msg): void
    {
        $taskId = $msg['task_id'] ?? '';
        $agentId = $msg['agent_id'] ?? '';
        $targetNode = $msg['node_id'] ?? '';

        if ($targetNode !== $this->nodeId) {
            return; // Not for us
        }

        $agent = $this->db->getAgent($agentId);
        if (!$agent || $agent->nodeId !== $this->nodeId) {
            $this->log("TASK_ASSIGN: agent {$agentId} not found locally");
            return;
        }

        if ($agent->status !== 'idle' || $agent->currentTaskId !== null) {
            $this->log("TASK_ASSIGN: agent {$agent->name} is busy, rejecting");
            return;
        }

        $task = $this->db->getTask($taskId);
        if (!$task) {
            $this->log("TASK_ASSIGN: task {$taskId} not found");
            return;
        }

        // Claim the task locally
        $claimed = $this->taskQueue->claim($taskId, $agentId);
        if (!$claimed) {
            $this->log("TASK_ASSIGN: could not claim task {$taskId} for {$agent->name}");
            return;
        }

        // Update agent status
        $this->db->updateAgentStatus($agentId, 'busy', $taskId);

        // Deliver to tmux
        $task = $this->db->getTask($taskId); // Re-read after claim
        if ($task) {
            $delivered = $this->agentBridge->deliverTask($agent, $task);
            if (!$delivered) {
                $this->taskQueue->fail($taskId, $agentId, 'Failed to deliver task to tmux session');
                $this->db->updateAgentStatus($agentId, 'idle', null);
                return;
            }
        }

        $this->log("TASK_ASSIGN: delivered task '{$task->title}' to agent {$agent->name}");
        $this->wsHandler?->pushTaskEvent('task_assigned', [
            'task_id' => $taskId,
            'agent_id' => $agentId,
            'title' => $task->title ?? '',
        ]);
    }

    private function spawnReplacementWorker(): void
    {
        $newHttpPort = $this->httpPort + 10;
        $newP2pPort = $this->p2pPort + 10;
        $binPath = realpath(__DIR__ . '/../../bin/voidlux');

        if (!$binPath) {
            $this->log("Cannot spawn replacement worker: bin/voidlux not found");
            return;
        }

        $cmd = sprintf(
            'php %s swarm --role=worker --http-port=%d --p2p-port=%d --seeds=%s --data-dir=%s',
            escapeshellarg($binPath),
            $newHttpPort,
            $newP2pPort,
            escapeshellarg("127.0.0.1:{$this->p2pPort}"),
            escapeshellarg($this->dataDir)
        );

        $this->log("Spawning replacement worker: {$cmd}");

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (is_resource($process)) {
            // Detach — we don't wait for the child
            proc_close($process);
            $this->log("Replacement worker spawned on HTTP:{$newHttpPort} P2P:{$newP2pPort}");
        } else {
            $this->log("Failed to spawn replacement worker");
        }
    }

    private function log(string $message): void
    {
        $short = substr($this->nodeId, 0, 8);
        $time = date('H:i:s');
        echo "[{$time}][{$short}][swarm] {$message}\n";
    }
}
