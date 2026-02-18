<?php

declare(strict_types=1);

namespace VoidLux\Swarm;

use Swoole\Coroutine;
use VoidLux\P2P\Protocol\LamportClock;
use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\TcpMesh;
use VoidLux\Swarm\Model\SwarmNodeModel;
use VoidLux\Swarm\Storage\SwarmDatabase;

/**
 * Tracks active swarm nodes, their capabilities, and current workload.
 *
 * Nodes auto-register on P2P connection via HELLO. Registration is gossiped
 * using SWARM_NODE_REGISTER messages so all peers know about every node in
 * the mesh. Periodic heartbeats update workload metrics (agent count, active
 * task count, memory, uptime) and detect offline nodes.
 *
 * This is the node-level analog of AgentRegistry — agents are individual
 * tmux sessions, while nodes are the swarm server processes that host them.
 */
class Registry
{
    private const HEARTBEAT_INTERVAL = 10;
    private const OFFLINE_THRESHOLD = 30;

    private bool $running = false;
    private float $startTime;

    /** @var array<string, true> Dedup for gossiped registration messages */
    private array $seenMessages = [];
    private int $seenLimit = 5000;

    public function __construct(
        private readonly SwarmDatabase $db,
        private readonly TcpMesh $mesh,
        private readonly LamportClock $clock,
        private readonly string $nodeId,
        private readonly string $role,
        private readonly string $httpHost,
        private readonly int $httpPort,
        private readonly int $p2pPort,
    ) {
        $this->startTime = microtime(true);
    }

    /**
     * Register the local node and start the heartbeat loop.
     *
     * Called once during swarm startup. The local node is immediately persisted
     * and gossiped so peers discover it before the first heartbeat fires.
     */
    public function start(): void
    {
        $this->running = true;

        // Register ourselves
        $this->registerLocal();

        // Heartbeat loop: broadcast workload metrics + detect offline peers
        Coroutine::create(function () {
            while ($this->running) {
                Coroutine::sleep(self::HEARTBEAT_INTERVAL);
                $this->broadcastHeartbeat();
                $this->detectOfflineNodes();
            }
        });
    }

    public function stop(): void
    {
        $this->running = false;

        // Mark ourselves offline on graceful shutdown
        $ts = $this->clock->tick();
        $node = $this->db->getSwarmNode($this->nodeId);
        if ($node) {
            $updated = $node->withStatus('offline', $ts);
            $this->db->upsertSwarmNode($updated);
            $this->gossipNodeStatus($updated);
        }
    }

    /**
     * Register the local swarm node and gossip it to peers.
     */
    private function registerLocal(): void
    {
        $ts = $this->clock->tick();
        $node = SwarmNodeModel::create(
            nodeId: $this->nodeId,
            role: $this->role,
            httpHost: $this->httpHost,
            httpPort: $this->httpPort,
            p2pPort: $this->p2pPort,
            lamportTs: $ts,
            capabilities: $this->gatherLocalCapabilities(),
        );

        // Collect initial workload from DB
        $agentCount = count($this->db->getLocalAgents($this->nodeId));
        $activeTaskCount = count($this->db->getOrphanedTasks($this->nodeId));
        $node = $node->withWorkload($agentCount, $activeTaskCount);
        $node = $node->withHealth(
            microtime(true) - $this->startTime,
            memory_get_usage(true),
        );

        $this->db->upsertSwarmNode($node);
        $this->gossipNodeRegister($node);

        $this->log("Registered local node (role: {$this->role})");
    }

    /**
     * Handle a SWARM_NODE_REGISTER message from a peer.
     * Returns the node model if new, null if duplicate.
     */
    public function receiveNodeRegister(array $msg, ?string $senderAddress = null): ?SwarmNodeModel
    {
        $nodeData = $msg['node'] ?? [];
        $nodeId = $nodeData['node_id'] ?? '';
        $key = 'node_reg:' . $nodeId;

        if (!$nodeId || isset($this->seenMessages[$key])) {
            return null;
        }
        $this->seenMessages[$key] = true;
        $this->clock->witness($nodeData['lamport_ts'] ?? 0);

        $node = SwarmNodeModel::fromArray($nodeData);
        $this->db->upsertSwarmNode($node);

        // Forward to peers (except sender)
        $this->mesh->broadcast(
            $msg + ['type' => MessageTypes::SWARM_NODE_REGISTER],
            $senderAddress,
        );
        $this->pruneSeenMessages();

        return $node;
    }

    /**
     * Handle a SWARM_NODE_STATUS message (heartbeat) from a peer.
     */
    public function receiveNodeStatus(array $msg, ?string $senderAddress = null): ?SwarmNodeModel
    {
        $nodeData = $msg['node'] ?? [];
        $nodeId = $nodeData['node_id'] ?? '';
        $ts = $nodeData['lamport_ts'] ?? 0;
        $key = 'node_hb:' . $nodeId . ':' . $ts;

        if (!$nodeId || isset($this->seenMessages[$key])) {
            return null;
        }
        $this->seenMessages[$key] = true;
        $this->clock->witness($ts);

        $node = SwarmNodeModel::fromArray($nodeData);

        // If we don't know this node, treat as registration
        $existing = $this->db->getSwarmNode($nodeId);
        if (!$existing) {
            $this->db->upsertSwarmNode($node);
        } else {
            // Only update if incoming ts is higher (causal ordering)
            if ($ts > $existing->lamportTs) {
                $this->db->upsertSwarmNode($node);
            }
        }

        // Forward to peers
        $this->mesh->broadcast(
            $msg + ['type' => MessageTypes::SWARM_NODE_STATUS],
            $senderAddress,
        );
        $this->pruneSeenMessages();

        return $node;
    }

    /**
     * Handle a peer HELLO by auto-registering the node if unknown.
     * Called from Server.onPeerMessage when type is HELLO.
     */
    public function onPeerHello(string $peerNodeId, string $peerRole, string $peerHost, int $httpPort, int $p2pPort): void
    {
        $existing = $this->db->getSwarmNode($peerNodeId);
        if ($existing) {
            // Refresh heartbeat
            $ts = $this->clock->tick();
            $updated = $existing->withStatus('online', $ts);
            $this->db->upsertSwarmNode($updated);
            return;
        }

        // Auto-register the peer node
        $ts = $this->clock->tick();
        $node = SwarmNodeModel::create(
            nodeId: $peerNodeId,
            role: $peerRole,
            httpHost: $peerHost,
            httpPort: $httpPort,
            p2pPort: $p2pPort,
            lamportTs: $ts,
        );
        $this->db->upsertSwarmNode($node);
        $this->log("Auto-registered peer node: " . substr($peerNodeId, 0, 8) . " ({$peerRole})");
    }

    /**
     * Handle a peer disconnection by marking the node offline.
     */
    public function onPeerDisconnect(string $peerNodeId): void
    {
        if (!$peerNodeId) {
            return;
        }

        $node = $this->db->getSwarmNode($peerNodeId);
        if ($node && $node->status !== 'offline') {
            $ts = $this->clock->tick();
            $updated = $node->withStatus('offline', $ts);
            $this->db->upsertSwarmNode($updated);
            $this->log("Node offline: " . substr($peerNodeId, 0, 8));
        }
    }

    // --- Query methods ---

    public function getNode(string $nodeId): ?SwarmNodeModel
    {
        return $this->db->getSwarmNode($nodeId);
    }

    /** @return SwarmNodeModel[] */
    public function getAllNodes(): array
    {
        return $this->db->getAllSwarmNodes();
    }

    /** @return SwarmNodeModel[] */
    public function getOnlineNodes(): array
    {
        return $this->db->getSwarmNodesByStatus('online');
    }

    /** @return SwarmNodeModel[] */
    public function getNodesByRole(string $role): array
    {
        return $this->db->getSwarmNodesByRole($role);
    }

    /**
     * Get the node with the lowest workload (for load-balanced dispatch).
     * Returns the online worker node with the fewest active tasks relative
     * to its agent count.
     */
    public function getLeastLoadedWorker(): ?SwarmNodeModel
    {
        $workers = $this->db->getSwarmNodesByRole('worker');
        $best = null;
        $bestLoad = PHP_FLOAT_MAX;

        foreach ($workers as $worker) {
            if ($worker->status !== 'online') {
                continue;
            }
            // Load ratio: active tasks / max(agent count, 1)
            $load = $worker->agentCount > 0
                ? $worker->activeTaskCount / $worker->agentCount
                : ($worker->activeTaskCount > 0 ? PHP_FLOAT_MAX : 0.0);

            if ($load < $bestLoad) {
                $bestLoad = $load;
                $best = $worker;
            }
        }

        return $best;
    }

    /**
     * Get aggregated workload distribution across all online nodes.
     *
     * @return array{total_nodes: int, online_nodes: int, total_agents: int, total_active_tasks: int, nodes: array}
     */
    public function getWorkloadDistribution(): array
    {
        $nodes = $this->db->getAllSwarmNodes();
        $online = 0;
        $totalAgents = 0;
        $totalActiveTasks = 0;
        $nodeDetails = [];

        foreach ($nodes as $node) {
            if ($node->status === 'online') {
                $online++;
                $totalAgents += $node->agentCount;
                $totalActiveTasks += $node->activeTaskCount;
            }
            $nodeDetails[] = [
                'node_id' => $node->nodeId,
                'role' => $node->role,
                'status' => $node->status,
                'agent_count' => $node->agentCount,
                'active_task_count' => $node->activeTaskCount,
            ];
        }

        return [
            'total_nodes' => count($nodes),
            'online_nodes' => $online,
            'total_agents' => $totalAgents,
            'total_active_tasks' => $totalActiveTasks,
            'nodes' => $nodeDetails,
        ];
    }

    // --- Gossip ---

    private function gossipNodeRegister(SwarmNodeModel $node): void
    {
        $key = 'node_reg:' . $node->nodeId;
        $this->seenMessages[$key] = true;

        $this->mesh->broadcast([
            'type' => MessageTypes::SWARM_NODE_REGISTER,
            'node' => $node->toArray(),
        ]);
    }

    private function gossipNodeStatus(SwarmNodeModel $node): void
    {
        $key = 'node_hb:' . $node->nodeId . ':' . $node->lamportTs;
        $this->seenMessages[$key] = true;

        $this->mesh->broadcast([
            'type' => MessageTypes::SWARM_NODE_STATUS,
            'node' => $node->toArray(),
        ]);
    }

    // --- Heartbeat loop ---

    private function broadcastHeartbeat(): void
    {
        $ts = $this->clock->tick();

        // Refresh local workload metrics
        $agentCount = count($this->db->getLocalAgents($this->nodeId));
        $orphaned = $this->db->getOrphanedTasks($this->nodeId);
        $activeTaskCount = count($orphaned);

        $node = $this->db->getSwarmNode($this->nodeId);
        if (!$node) {
            return;
        }

        $updated = new SwarmNodeModel(
            nodeId: $node->nodeId,
            role: $this->role,
            httpHost: $node->httpHost,
            httpPort: $node->httpPort,
            p2pPort: $node->p2pPort,
            capabilities: $this->gatherLocalCapabilities(),
            agentCount: $agentCount,
            activeTaskCount: $activeTaskCount,
            status: 'online',
            lastHeartbeat: gmdate('Y-m-d\TH:i:s\Z'),
            lamportTs: $ts,
            registeredAt: $node->registeredAt,
            uptimeSeconds: microtime(true) - $this->startTime,
            memoryUsageBytes: memory_get_usage(true),
        );

        $this->db->upsertSwarmNode($updated);
        $this->gossipNodeStatus($updated);
    }

    private function detectOfflineNodes(): void
    {
        $nodes = $this->db->getAllSwarmNodes();
        $now = time();

        foreach ($nodes as $node) {
            if ($node->nodeId === $this->nodeId) {
                continue; // Don't mark ourselves offline
            }
            if ($node->status === 'offline') {
                continue;
            }
            if (!$node->lastHeartbeat) {
                continue;
            }

            $lastBeat = strtotime($node->lastHeartbeat);
            if ($lastBeat && ($now - $lastBeat) > self::OFFLINE_THRESHOLD) {
                $ts = $this->clock->tick();
                $updated = $node->withStatus('offline', $ts);
                $this->db->upsertSwarmNode($updated);
                $this->log("Node timed out: " . substr($node->nodeId, 0, 8));
            }
        }
    }

    /**
     * Gather capabilities from all local agents (union of agent capabilities).
     */
    private function gatherLocalCapabilities(): array
    {
        $agents = $this->db->getLocalAgents($this->nodeId);
        $caps = [];
        foreach ($agents as $agent) {
            foreach ($agent->capabilities as $cap) {
                $caps[$cap] = true;
            }
        }
        return array_keys($caps);
    }

    private function pruneSeenMessages(): void
    {
        if (count($this->seenMessages) > $this->seenLimit) {
            $this->seenMessages = array_slice($this->seenMessages, -($this->seenLimit / 2), null, true);
        }
    }

    private function log(string $message): void
    {
        $short = substr($this->nodeId, 0, 8);
        $time = date('H:i:s');
        echo "[{$time}][{$short}][registry] {$message}\n";
    }
}
