<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Gossip;

use Swoole\Coroutine;
use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\Connection;
use VoidLux\P2P\Transport\TcpMesh;
use VoidLux\Swarm\Model\AgentModel;
use VoidLux\Swarm\Storage\SwarmDatabase;

/**
 * Pull-based agent consistency repair.
 * Periodically picks a random peer and exchanges agent registries,
 * inserting any agents the local node doesn't know about.
 */
class AgentAntiEntropy
{
    private bool $running = false;

    public function __construct(
        private readonly TcpMesh $mesh,
        private readonly SwarmDatabase $db,
        private readonly string $nodeId = '',
        private readonly int $interval = 30,
    ) {}

    public function start(): void
    {
        $this->running = true;

        Coroutine::create(function () {
            while ($this->running) {
                Coroutine::sleep($this->interval);
                $this->syncWithRandomPeer();
            }
        });
    }

    private function syncWithRandomPeer(): void
    {
        $connections = $this->mesh->getConnections();
        if (empty($connections)) {
            return;
        }

        /** @var Connection $peer */
        $peer = $connections[array_rand($connections)];
        if ($peer->isClosed()) {
            return;
        }

        $maxTs = $this->db->getMaxAgentLamportTs();
        $peer->send([
            'type' => MessageTypes::AGENT_SYNC_REQ,
            'since_lamport_ts' => $maxTs,
        ]);
    }

    /**
     * Immediately request agent sync from a newly connected peer.
     * Fires on every HELLO so we don't depend on the periodic timer
     * surviving the peer dedup churn.
     */
    public function syncFromPeer(Connection $conn): void
    {
        if ($conn->isClosed()) {
            return;
        }
        $maxTs = $this->db->getMaxAgentLamportTs();
        $conn->send([
            'type' => MessageTypes::AGENT_SYNC_REQ,
            'since_lamport_ts' => $maxTs,
        ]);
    }

    public function handleSyncRequest(Connection $conn, array $message): void
    {
        $sinceLamportTs = $message['since_lamport_ts'] ?? 0;
        $agents = $this->db->getAgentsSince($sinceLamportTs);

        $conn->send([
            'type' => MessageTypes::AGENT_SYNC_RSP,
            'agents' => array_map(fn(AgentModel $a) => $a->toArray(), $agents),
        ]);
    }

    public function handleSyncResponse(array $message): int
    {
        $count = 0;
        foreach ($message['agents'] ?? [] as $agentData) {
            $id = $agentData['id'] ?? '';
            if (!$id) {
                continue;
            }

            // Never overwrite local agents — this node is authoritative for its own
            $agentNodeId = $agentData['node_id'] ?? '';
            if ($this->nodeId !== '' && $agentNodeId === $this->nodeId) {
                continue;
            }

            // Only insert if we don't have this agent or ours is older
            $existing = $this->db->getAgent($id);
            if ($existing === null || ($agentData['lamport_ts'] ?? 0) > $existing->lamportTs) {
                $agent = AgentModel::fromArray($agentData);
                $this->db->insertAgent($agent);
                $count++;
            }
        }
        return $count;
    }

    public function stop(): void
    {
        $this->running = false;
    }
}
