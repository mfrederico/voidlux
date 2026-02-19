<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Gossip;

use Swoole\Coroutine;
use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\Connection;
use VoidLux\P2P\Transport\TcpMesh;
use VoidLux\Swarm\Model\TaskStatus;
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

        // Also sync board messages
        $maxBoardTs = $this->db->getMaxMessageLamportTs();
        $peer->send([
            'type' => MessageTypes::BOARD_SYNC_REQ,
            'since_lamport_ts' => $maxBoardTs,
        ]);
    }

    public function handleSyncRequest(Connection $conn, array $message): void
    {
        $sinceLamportTs = $message['since_lamport_ts'] ?? 0;
        $tasks = $this->db->getTasksSince($sinceLamportTs);

        // Exclude archived tasks from sync responses — peers don't need them
        $filtered = array_filter($tasks, fn($t) => !$t->archived);

        $conn->send([
            'type' => MessageTypes::TASK_SYNC_RSP,
            'tasks' => array_map(fn($t) => $t->toArray(), $filtered),
        ]);
    }

    public function handleSyncResponse(array $message): int
    {
        $count = 0;
        foreach ($message['tasks'] ?? [] as $taskData) {
            $id = $taskData['id'] ?? '';
            if ($id !== '') {
                // Skip tasks that are already terminal or archived locally
                $local = $this->db->getTask($id);
                if ($local && ($local->status->isTerminal() || $local->archived)) {
                    continue;
                }
            }

            $task = $this->gossip->receiveTaskCreate($taskData);
            if ($task !== null) {
                $count++;
            } else {
                // Task already exists — merge in fields that may be missing locally
                // (e.g. gitBranch set on worker node, not yet propagated)
                $this->mergeTaskFields($taskData);
            }
        }
        return $count;
    }

    /**
     * Merge specific fields from a remote task into the local copy.
     * Only updates fields that are empty locally but populated remotely.
     */
    private function mergeTaskFields(array $remoteData): void
    {
        $id = $remoteData['id'] ?? '';
        if (!$id) {
            return;
        }

        $local = $this->db->getTask($id);
        if (!$local) {
            return;
        }

        // Don't merge into terminal or archived tasks
        if ($local->status->isTerminal() || $local->archived) {
            return;
        }

        // Merge gitBranch: worker sets it during delivery, emperor needs it for merge
        $remoteGitBranch = $remoteData['git_branch'] ?? '';
        if ($remoteGitBranch !== '' && $local->gitBranch === '') {
            $this->db->updateGitBranch($id, $remoteGitBranch);
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }
}
