<?php

declare(strict_types=1);

namespace VoidLux\P2P\Gossip;

use Swoole\Coroutine;
use VoidLux\App\GraffitiWall\Database;
use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\Connection;
use VoidLux\P2P\Transport\TcpMesh;

/**
 * Pull-based consistency repair.
 * Every 60 seconds, pick a random connected peer and request posts since our last known lamport_ts.
 */
class AntiEntropy
{
    private bool $running = false;

    public function __construct(
        private readonly TcpMesh $mesh,
        private readonly Database $db,
        private readonly GossipEngine $gossip,
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

        $maxTs = $this->db->getMaxLamportTs();
        $peer->send([
            'type' => MessageTypes::SYNC_REQ,
            'since_lamport_ts' => $maxTs,
        ]);
    }

    /**
     * Handle SYNC_REQ: respond with posts the requester is missing.
     */
    public function handleSyncRequest(Connection $conn, array $message): void
    {
        $sinceLamportTs = $message['since_lamport_ts'] ?? 0;
        $posts = $this->db->getPostsSince($sinceLamportTs);

        $conn->send([
            'type' => MessageTypes::SYNC_RSP,
            'posts' => array_map(fn($p) => $p->toArray(), $posts),
        ]);
    }

    /**
     * Handle SYNC_RSP: ingest posts from sync response.
     */
    public function handleSyncResponse(array $message): int
    {
        $count = 0;
        foreach ($message['posts'] ?? [] as $postData) {
            $post = $this->gossip->receivePost($postData);
            if ($post !== null) {
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
