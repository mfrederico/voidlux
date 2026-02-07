<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Gossip;

use Swoole\Coroutine;
use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\Connection;
use VoidLux\P2P\Transport\TcpMesh;
use VoidLux\Swarm\Storage\SwarmDatabase;

/**
 * Pull-based task consistency repair.
 * Periodically picks a random peer and syncs tasks since local max lamport_ts.
 */
class TaskAntiEntropy
{
    private bool $running = false;

    public function __construct(
        private readonly TcpMesh $mesh,
        private readonly SwarmDatabase $db,
        private readonly TaskGossipEngine $gossip,
        private readonly int $interval = 60,
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

        $maxTs = $this->db->getMaxTaskLamportTs();
        $peer->send([
            'type' => MessageTypes::TASK_SYNC_REQ,
            'since_lamport_ts' => $maxTs,
        ]);
    }

    public function handleSyncRequest(Connection $conn, array $message): void
    {
        $sinceLamportTs = $message['since_lamport_ts'] ?? 0;
        $tasks = $this->db->getTasksSince($sinceLamportTs);

        $conn->send([
            'type' => MessageTypes::TASK_SYNC_RSP,
            'tasks' => array_map(fn($t) => $t->toArray(), $tasks),
        ]);
    }

    public function handleSyncResponse(array $message): int
    {
        $count = 0;
        foreach ($message['tasks'] ?? [] as $taskData) {
            $task = $this->gossip->receiveTaskCreate($taskData);
            if ($task !== null) {
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
