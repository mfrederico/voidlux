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
 *
 * Emperor-authoritative model:
 *   - Workers pull from emperor (authoritative task state)
 *   - Emperor never imports tasks from workers (only merges worker-side fields like gitBranch)
 *   - This prevents zombie resurrection: workers can't push stale tasks back to emperor
 */
class TaskAntiEntropy
{
    private bool $running = false;

    public function __construct(
        private readonly TcpMesh $mesh,
        private readonly SwarmDatabase $db,
        private readonly TaskGossipEngine $gossip,
        private bool $authoritative = false,
        private readonly int $interval = 60,
    ) {}

    public function setAuthoritative(bool $authoritative): void
    {
        $this->authoritative = $authoritative;
    }

    public function start(): void
    {
        $this->running = true;

        Coroutine::create(function () {
            while ($this->running) {
                Coroutine::sleep($this->interval);
                if (!$this->authoritative) {
                    // Only workers initiate sync — they pull from emperor
                    $this->syncWithRandomPeer();
                }
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
        $tasks = $this->db->getTasksSince($sinceLamportTs); // excludes archived

        $conn->send([
            'type' => MessageTypes::TASK_SYNC_RSP,
            'tasks' => array_map(fn($t) => $t->toArray(), $tasks),
        ]);
    }

    /**
     * Handle sync response from a peer.
     *
     * If authoritative (emperor): only merge worker-side fields (gitBranch),
     * never import tasks. Emperor is the source of truth.
     *
     * If non-authoritative (worker): import tasks from emperor as authoritative state.
     */
    public function handleSyncResponse(array $message): int
    {
        $count = 0;
        foreach ($message['tasks'] ?? [] as $taskData) {
            if ($this->authoritative) {
                // Emperor: never import tasks from workers, only merge fields
                $this->mergeTaskFields($taskData);
                continue;
            }

            // Worker: import from emperor (authoritative)
            $task = $this->gossip->receiveTaskCreate($taskData);
            if ($task !== null) {
                $count++;
            } else {
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
        if (!$local || $local->archived) {
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
