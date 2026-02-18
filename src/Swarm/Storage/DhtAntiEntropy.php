<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Storage;

use VoidLux\P2P\PeerManager;
use VoidLux\P2P\Protocol\LamportClock;
use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\TcpMesh;

/**
 * Pull-based anti-entropy for DHT consistency repair.
 *
 * Periodically picks a random peer, sends a sync request with local max lamport_ts,
 * and ingests any entries the peer has that are newer. This repairs missed gossip
 * messages and ensures eventual consistency across all nodes.
 */
class DhtAntiEntropy
{
    private bool $running = false;

    public function __construct(
        private readonly TcpMesh $mesh,
        private readonly DhtStorage $storage,
        private readonly DhtEngine $engine,
        private readonly PeerManager $peerManager,
        private readonly LamportClock $clock,
        private readonly string $nodeId,
        private readonly int $syncIntervalSeconds = 45,
        private readonly int $purgeIntervalSeconds = 120,
    ) {}

    /**
     * Start the anti-entropy coroutine loops:
     * 1. Periodic sync with random peer
     * 2. Periodic purge of expired entries and old tombstones
     */
    public function start(): void
    {
        $this->running = true;

        // Sync loop
        \Swoole\Coroutine::create(function () {
            while ($this->running) {
                \Swoole\Coroutine::sleep($this->syncIntervalSeconds);
                if (!$this->running) {
                    break;
                }
                try {
                    $this->syncWithRandomPeer();
                } catch (\Throwable $e) {
                    $this->log("Sync error: " . $e->getMessage());
                }
            }
        });

        // Purge loop
        \Swoole\Coroutine::create(function () {
            while ($this->running) {
                \Swoole\Coroutine::sleep($this->purgeIntervalSeconds);
                if (!$this->running) {
                    break;
                }
                try {
                    $purged = $this->storage->purgeExpired();
                    if ($purged > 0) {
                        $this->log("Purged {$purged} expired/tombstoned entries");
                    }
                } catch (\Throwable $e) {
                    $this->log("Purge error: " . $e->getMessage());
                }
            }
        });
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Pick a random connected peer and request DHT entries since our max lamport_ts.
     */
    private function syncWithRandomPeer(): void
    {
        $peers = $this->peerManager->getConnectedPeers();
        if (empty($peers)) {
            return;
        }

        $peerIds = array_keys($peers);
        $targetId = $peerIds[array_rand($peerIds)];

        $sinceTs = $this->storage->getMaxLamportTs();

        $this->mesh->sendTo($targetId, [
            'type' => MessageTypes::DHT_SYNC_REQ,
            'since_lamport_ts' => $sinceTs,
            'node_id' => $this->nodeId,
            'lamport_ts' => $this->clock->tick(),
        ]);

        $this->log("Sync request to peer " . substr($targetId, 0, 8) . " (since ts={$sinceTs})");
    }

    /**
     * Eagerly sync from a specific peer connection (e.g., on new connection).
     */
    public function syncFromPeer(\VoidLux\P2P\Transport\Connection $conn): void
    {
        $sinceTs = $this->storage->getMaxLamportTs();

        $conn->send([
            'type' => MessageTypes::DHT_SYNC_REQ,
            'since_lamport_ts' => $sinceTs,
            'node_id' => $this->nodeId,
            'lamport_ts' => $this->clock->tick(),
        ]);
    }

    private function log(string $msg): void
    {
        $short = substr($this->nodeId, 0, 8);
        echo "[DhtSync:{$short}] {$msg}\n";
    }
}
