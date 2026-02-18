<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Gossip;

use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\Connection;
use VoidLux\P2P\Transport\TcpMesh;

/**
 * Periodic pull-based sync for marketplace state convergence.
 *
 * Every interval, picks a random peer and requests their marketplace
 * state. Merges any missing offerings, bounties, capability profiles,
 * and tributes into local state. Same pattern as TaskAntiEntropy.
 */
class MarketplaceAntiEntropy
{
    private int $intervalSeconds;

    public function __construct(
        private readonly TcpMesh $mesh,
        private readonly MarketplaceGossipEngine $gossipEngine,
        int $intervalSeconds = 120,
    ) {
        $this->intervalSeconds = $intervalSeconds;
    }

    /**
     * Handle an incoming MARKETPLACE_SYNC_REQ from a peer.
     * Responds with the full marketplace state.
     */
    public function handleSyncRequest(Connection $conn): void
    {
        $response = $this->gossipEngine->buildSyncResponse();
        $conn->send($response);
    }

    /**
     * Handle an incoming MARKETPLACE_SYNC_RSP from a peer.
     * Merges remote state into local marketplace.
     */
    public function handleSyncResponse(array $msg): void
    {
        $this->gossipEngine->receiveSyncResponse($msg);
    }

    /**
     * Request sync from a specific peer connection.
     */
    public function syncFromPeer(Connection $conn): void
    {
        $conn->send($this->gossipEngine->buildSyncRequest());
    }

    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }
}
