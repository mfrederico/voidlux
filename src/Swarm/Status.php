<?php

declare(strict_types=1);

namespace VoidLux\Swarm;

use Swoole\Coroutine;
use VoidLux\P2P\Protocol\LamportClock;
use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\Connection;
use VoidLux\P2P\Transport\TcpMesh;
use VoidLux\Swarm\Model\SwarmNodeModel;
use VoidLux\Swarm\Storage\SwarmDatabase;

/**
 * Real-time status tracking for all swarm nodes.
 *
 * Runs coroutine-based health check loops that:
 * - Ping nodes to measure response time and detect unresponsive peers
 * - Aggregate workload metrics across the mesh
 * - Fire callbacks on status changes (online/offline/degraded)
 * - Provide a snapshot API for the dashboard
 *
 * Works alongside Registry — Registry handles node registration and gossip,
 * Status handles active monitoring and health assessment.
 */
class Status
{
    private const HEALTH_CHECK_INTERVAL = 15;
    private const PING_TIMEOUT = 5;
    private const DEGRADED_RESPONSE_MS = 2000;

    private bool $running = false;

    /** @var callable|null fn(string $nodeId, string $oldStatus, string $newStatus, SwarmNodeModel $node): void */
    private $onStatusChange = null;

    /** @var array<string, float> Pending pings: node_id → send timestamp */
    private array $pendingPings = [];

    /** @var array<string, float> Last measured round-trip time: node_id → ms */
    private array $responseTimeMs = [];

    public function __construct(
        private readonly SwarmDatabase $db,
        private readonly TcpMesh $mesh,
        private readonly LamportClock $clock,
        private readonly Registry $registry,
        private readonly string $nodeId,
    ) {}

    /**
     * Register a callback for node status transitions.
     */
    public function onStatusChange(callable $callback): void
    {
        $this->onStatusChange = $callback;
    }

    /**
     * Start the health check coroutine loop.
     */
    public function start(): void
    {
        $this->running = true;

        // Main health check loop
        Coroutine::create(function () {
            while ($this->running) {
                Coroutine::sleep(self::HEALTH_CHECK_INTERVAL);
                $this->runHealthChecks();
            }
        });
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Get a full snapshot of swarm status for the dashboard.
     *
     * @return array{
     *   local_node: array,
     *   nodes: array,
     *   workload: array,
     *   health: array{healthy: int, degraded: int, offline: int},
     *   uptime_seconds: float,
     * }
     */
    public function getSnapshot(): array
    {
        $nodes = $this->db->getAllSwarmNodes();
        $healthy = 0;
        $degraded = 0;
        $offline = 0;
        $nodeData = [];

        foreach ($nodes as $node) {
            $rtt = $this->responseTimeMs[$node->nodeId] ?? null;
            $healthStatus = $this->assessNodeHealth($node, $rtt);

            if ($healthStatus === 'healthy') {
                $healthy++;
            } elseif ($healthStatus === 'degraded') {
                $degraded++;
            } else {
                $offline++;
            }

            $nodeData[] = array_merge($node->toArray(), [
                'health' => $healthStatus,
                'response_time_ms' => $rtt,
            ]);
        }

        $localNode = $this->db->getSwarmNode($this->nodeId);

        return [
            'local_node' => $localNode?->toArray() ?? [],
            'nodes' => $nodeData,
            'workload' => $this->registry->getWorkloadDistribution(),
            'health' => [
                'healthy' => $healthy,
                'degraded' => $degraded,
                'offline' => $offline,
            ],
        ];
    }

    /**
     * Get the measured response time for a specific node (or null if not measured).
     */
    public function getResponseTimeMs(string $nodeId): ?float
    {
        return $this->responseTimeMs[$nodeId] ?? null;
    }

    /**
     * Handle a PONG response and record the round-trip time.
     * Called from Server.onPeerMessage when type is PONG.
     */
    public function onPong(string $peerNodeId, float $sentTimestamp): void
    {
        if (isset($this->pendingPings[$peerNodeId])) {
            $rtt = (microtime(true) - $this->pendingPings[$peerNodeId]) * 1000;
            $this->responseTimeMs[$peerNodeId] = $rtt;
            unset($this->pendingPings[$peerNodeId]);
        }
    }

    // --- Health check loop ---

    private function runHealthChecks(): void
    {
        $nodes = $this->db->getAllSwarmNodes();

        foreach ($nodes as $node) {
            if ($node->nodeId === $this->nodeId) {
                // Local node is always healthy if we're running
                $this->responseTimeMs[$this->nodeId] = 0.0;
                continue;
            }

            if ($node->status === 'offline') {
                continue;
            }

            // Send a PING to measure responsiveness
            $this->pingNode($node);
        }

        // After a short wait, check for timed-out pings
        Coroutine::create(function () {
            Coroutine::sleep(self::PING_TIMEOUT);
            $this->checkPingTimeouts();
        });
    }

    private function pingNode(SwarmNodeModel $node): void
    {
        $this->pendingPings[$node->nodeId] = microtime(true);

        $this->mesh->broadcast([
            'type' => MessageTypes::PING,
            'node_id' => $this->nodeId,
            'target_node' => $node->nodeId,
            'timestamp' => microtime(true),
        ]);
    }

    private function checkPingTimeouts(): void
    {
        $now = microtime(true);

        foreach ($this->pendingPings as $nodeId => $sentAt) {
            $elapsed = ($now - $sentAt) * 1000;

            if ($elapsed > self::PING_TIMEOUT * 1000) {
                // Node didn't respond — may be degraded
                unset($this->pendingPings[$nodeId]);
                $this->responseTimeMs[$nodeId] = $elapsed;

                $node = $this->db->getSwarmNode($nodeId);
                if ($node && $node->status === 'online') {
                    $this->transitionStatus($node, 'degraded');
                }
            }
        }
    }

    /**
     * Assess a node's health based on status and response time.
     */
    private function assessNodeHealth(SwarmNodeModel $node, ?float $rttMs): string
    {
        if ($node->status === 'offline') {
            return 'offline';
        }

        if ($node->nodeId === $this->nodeId) {
            return 'healthy';
        }

        if ($rttMs !== null && $rttMs > self::DEGRADED_RESPONSE_MS) {
            return 'degraded';
        }

        // Check if last heartbeat is recent (30s threshold matches Registry)
        if ($node->lastHeartbeat) {
            $age = time() - strtotime($node->lastHeartbeat);
            if ($age > 30) {
                return 'offline';
            }
        }

        return 'healthy';
    }

    /**
     * Transition a node to a new status, firing the callback if registered.
     */
    private function transitionStatus(SwarmNodeModel $node, string $newStatus): void
    {
        if ($node->status === $newStatus) {
            return;
        }

        $oldStatus = $node->status;
        $ts = $this->clock->tick();
        $updated = $node->withStatus($newStatus, $ts);
        $this->db->upsertSwarmNode($updated);

        $this->log("Node " . substr($node->nodeId, 0, 8) . " status: {$oldStatus} -> {$newStatus}");

        if ($this->onStatusChange) {
            ($this->onStatusChange)($node->nodeId, $oldStatus, $newStatus, $updated);
        }
    }

    private function log(string $message): void
    {
        $short = substr($this->nodeId, 0, 8);
        $time = date('H:i:s');
        echo "[{$time}][{$short}][status] {$message}\n";
    }
}
