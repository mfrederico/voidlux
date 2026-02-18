<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Broker;

use Swoole\Coroutine;
use VoidLux\P2P\Protocol\LamportClock;
use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\Connection;
use VoidLux\P2P\Transport\TcpMesh;

/**
 * Seneschal Broker Agent — central communication hub for the galactic marketplace.
 *
 * Enhances the Seneschal reverse proxy with active message brokering:
 * - Routes messages between emperors and agents across the P2P mesh
 * - Queues messages for offline nodes (store-and-forward)
 * - Buffers task creation requests during emperor transitions
 * - Mediates offer-pay protocol messages between trading nodes
 * - Maintains a directory of all known nodes and their capabilities
 *
 * The broker is lightweight: no SQLite, no agents, no task execution.
 * It only routes, queues, and mediates communication.
 */
class SeneschalBroker
{
    private NodeDirectory $nodeDirectory;
    private BrokerQueue $queue;
    private BrokerRouter $router;

    private bool $running = false;

    /** @var callable|null fn(string $msg): void */
    private $logCallback = null;

    public function __construct(
        private readonly TcpMesh $mesh,
        private readonly LamportClock $clock,
        private readonly string $nodeId,
    ) {
        $this->nodeDirectory = new NodeDirectory();
        $this->queue = new BrokerQueue();
        $this->router = new BrokerRouter($this->mesh, $this->nodeDirectory, $this->queue, $this->nodeId);

        // Wire queue delivery to mesh
        $this->queue->onDelivery(function (string $targetNodeId, array $msg): bool {
            if (!$this->nodeDirectory->isConnected($targetNodeId)) {
                return false;
            }
            return $this->mesh->sendTo($targetNodeId, $msg);
        });
    }

    public function onLog(callable $callback): void
    {
        $this->logCallback = $callback;
        $this->queue->onLog($callback);
        $this->router->onLog($callback);
    }

    /**
     * Start broker background coroutines.
     */
    public function start(): void
    {
        $this->running = true;

        // Queue delivery loop
        Coroutine::create(function () {
            $this->queue->start();
        });

        // Periodic node directory cleanup
        Coroutine::create(function () {
            while ($this->running) {
                Coroutine::sleep(30);
                $pruned = $this->nodeDirectory->pruneStale();
                if (!empty($pruned)) {
                    $this->log("Pruned " . count($pruned) . " stale node(s) from directory");
                }
            }
        });

        // Periodic node announce — tell the mesh we're a broker
        Coroutine::create(function () {
            while ($this->running) {
                $this->mesh->broadcast([
                    'type' => MessageTypes::BROKER_NODE_ANNOUNCE,
                    'node_id' => $this->nodeId,
                    'role' => 'seneschal',
                    'broker' => true,
                    'queue_depth' => $this->queue->totalPending(),
                    'connected_nodes' => $this->nodeDirectory->connectedCount(),
                    'lamport_ts' => $this->clock->tick(),
                ]);
                Coroutine::sleep(15);
            }
        });

        $this->log("Broker started: message routing, queue, and node directory active");
    }

    public function stop(): void
    {
        $this->running = false;
        $this->queue->stop();
    }

    // ── Peer lifecycle ────────────────────────────────────────────────

    /**
     * Called when a peer sends HELLO. Registers the node in the directory.
     */
    public function onPeerHello(Connection $conn, array $msg): void
    {
        $nodeId = $msg['node_id'] ?? '';
        $role = $msg['role'] ?? 'worker';
        $httpPort = $msg['http_port'] ?? 0;
        $p2pPort = $msg['p2p_port'] ?? 0;
        $capabilities = $msg['capabilities'] ?? [];

        if (!$nodeId) {
            return;
        }

        $this->nodeDirectory->upsert(
            nodeId: $nodeId,
            role: $role,
            host: $conn->remoteHost,
            httpPort: $httpPort,
            p2pPort: $p2pPort,
            capabilities: $capabilities,
        );
        $this->nodeDirectory->markConnected($nodeId);

        // Flush any queued messages for this node
        $this->queue->onNodeReconnected($nodeId);

        $this->log("Node registered: " . substr($nodeId, 0, 8) . " ({$role})");
    }

    /**
     * Called when a peer disconnects.
     */
    public function onPeerDisconnected(string $nodeId): void
    {
        if (!$nodeId) {
            return;
        }
        $this->nodeDirectory->markDisconnected($nodeId);
    }

    // ── Emperor tracking ──────────────────────────────────────────────

    /**
     * Called on EMPEROR_HEARTBEAT — update emperor in directory.
     */
    public function onEmperorHeartbeat(string $nodeId, string $host, int $httpPort, int $p2pPort): void
    {
        $this->nodeDirectory->upsert(
            nodeId: $nodeId,
            role: 'emperor',
            host: $host,
            httpPort: $httpPort,
            p2pPort: $p2pPort,
        );
    }

    /**
     * Called on ELECTION_VICTORY — new emperor elected.
     */
    public function onEmperorElected(string $nodeId, string $host, int $httpPort): void
    {
        // Update directory: demote old emperor, promote new one
        $oldEmperor = $this->nodeDirectory->getEmperor();
        if ($oldEmperor !== null && $oldEmperor->nodeId !== $nodeId) {
            $this->nodeDirectory->upsert(
                nodeId: $oldEmperor->nodeId,
                role: 'worker',
                host: $oldEmperor->host,
                httpPort: $oldEmperor->httpPort,
                p2pPort: $oldEmperor->p2pPort,
            );
        }

        $this->nodeDirectory->upsert(
            nodeId: $nodeId,
            role: 'emperor',
            host: $host,
            httpPort: $httpPort,
            p2pPort: 0, // Will be updated on next HELLO
        );

        // Flush emperor queue
        $this->queue->onEmperorElected($nodeId);

        $this->log("Emperor elected: " . substr($nodeId, 0, 8) . " — flushing buffered messages");
    }

    // ── Message handling ──────────────────────────────────────────────

    /**
     * Handle a P2P message relevant to the broker.
     * Returns true if the broker handled the message (caller should not process further).
     */
    public function handleMessage(Connection $conn, array $msg): bool
    {
        $type = $msg['type'] ?? 0;

        switch ($type) {
            case MessageTypes::BROKER_FORWARD:
                $ack = $this->router->handleForward($msg, $conn->address());
                $conn->send($ack);
                return true;

            case MessageTypes::BROKER_FORWARD_ACK:
                // ACKs are informational — nothing to do
                return true;

            case MessageTypes::BROKER_QUEUE_STATUS:
                $rsp = $this->router->handleQueueStatusRequest($msg);
                $conn->send($rsp);
                return true;

            case MessageTypes::BROKER_QUEUE_RSP:
                // Response to our own query — nothing to do
                return true;

            case MessageTypes::BROKER_NODE_ANNOUNCE:
                $this->handleNodeAnnounce($msg, $conn);
                return true;

            // Offer-Pay messages — mediate by routing to counterparty
            case MessageTypes::OFFER_CREATE:
            case MessageTypes::OFFER_ACCEPT:
            case MessageTypes::OFFER_REJECT:
            case MessageTypes::PAYMENT_INIT:
            case MessageTypes::PAYMENT_CONFIRM:
                $this->router->routeOfferMessage($msg);
                return false; // Let Seneschal's normal handler also process

            // Agent events — update node directory with agent counts
            case MessageTypes::AGENT_REGISTER:
                $this->updateNodeAgentCount($msg['node_id'] ?? '', 1);
                return false;

            case MessageTypes::AGENT_DEREGISTER:
                $this->updateNodeAgentCount($msg['node_id'] ?? '', -1);
                return false;

            // Task creation from external source — route through broker
            case MessageTypes::TASK_CREATE:
                // Forward to emperor if we see a task creation in the mesh
                $this->router->routeTaskCreation($msg);
                return false;

            default:
                return false;
        }
    }

    // ── HTTP API for broker status ────────────────────────────────────

    /**
     * Return broker status for the Seneschal's HTTP status endpoint.
     */
    public function getStatus(): array
    {
        return [
            'broker' => true,
            'node_id' => $this->nodeId,
            'routing' => $this->router->stats(),
            'node_directory' => $this->nodeDirectory->toArray(),
            'emperor' => $this->nodeDirectory->getEmperor()?->toArray(),
        ];
    }

    public function getNodeDirectory(): NodeDirectory
    {
        return $this->nodeDirectory;
    }

    public function getRouter(): BrokerRouter
    {
        return $this->router;
    }

    public function getQueue(): BrokerQueue
    {
        return $this->queue;
    }

    // ── Internal helpers ──────────────────────────────────────────────

    private function handleNodeAnnounce(array $msg, Connection $conn): void
    {
        $nodeId = $msg['node_id'] ?? '';
        $role = $msg['role'] ?? 'worker';

        if (!$nodeId || $nodeId === $this->nodeId) {
            return;
        }

        $this->clock->witness($msg['lamport_ts'] ?? 0);

        $this->nodeDirectory->upsert(
            nodeId: $nodeId,
            role: $role,
            host: $conn->remoteHost,
            httpPort: $msg['http_port'] ?? 0,
            p2pPort: $msg['p2p_port'] ?? 0,
            agentCount: $msg['agent_count'] ?? 0,
        );
    }

    private function updateNodeAgentCount(string $nodeId, int $delta): void
    {
        if (!$nodeId) {
            return;
        }

        $node = $this->nodeDirectory->get($nodeId);
        if ($node) {
            $this->nodeDirectory->upsert(
                nodeId: $node->nodeId,
                role: $node->role,
                host: $node->host,
                httpPort: $node->httpPort,
                p2pPort: $node->p2pPort,
                capabilities: $node->capabilities,
                agentCount: max(0, $node->agentCount + $delta),
            );
        }
    }

    private function log(string $message): void
    {
        if ($this->logCallback) {
            ($this->logCallback)("[broker] {$message}");
        }
    }
}
