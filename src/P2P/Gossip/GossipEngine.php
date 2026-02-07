<?php

declare(strict_types=1);

namespace VoidLux\P2P\Gossip;

use VoidLux\App\GraffitiWall\Database;
use VoidLux\App\GraffitiWall\PostModel;
use VoidLux\P2P\Protocol\LamportClock;
use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\TcpMesh;

/**
 * Push-based post dissemination.
 * On new post (local or remote), forward to all connected peers.
 * Deduplicates by UUID.
 */
class GossipEngine
{
    /** @var array<string, true> Set of seen post UUIDs for dedup */
    private array $seenPosts = [];
    private int $seenPostsLimit = 10000;

    public function __construct(
        private readonly TcpMesh $mesh,
        private readonly Database $db,
        private readonly LamportClock $clock,
    ) {}

    /**
     * Handle a new locally created post: store, gossip, return the model.
     */
    public function createPost(string $content, string $author, string $nodeId): PostModel
    {
        $ts = $this->clock->tick();
        $post = PostModel::create($content, $author, $nodeId, $ts);
        $this->db->insertPost($post);
        $this->seenPosts[$post->id] = true;
        $this->gossipPost($post);
        return $post;
    }

    /**
     * Handle a post received from a peer.
     * Returns the post if it was new, null if duplicate.
     */
    public function receivePost(array $postData, ?string $senderAddress = null): ?PostModel
    {
        $id = $postData['id'] ?? '';
        if (!$id || isset($this->seenPosts[$id])) {
            return null;
        }

        if ($this->db->hasPost($id)) {
            $this->seenPosts[$id] = true;
            return null;
        }

        $this->clock->witness($postData['lamport_ts'] ?? 0);

        $post = PostModel::fromArray($postData);
        $this->db->insertPost($post);
        $this->seenPosts[$id] = true;

        // Forward to all peers except sender
        $this->gossipPost($post, $senderAddress);

        $this->pruneSeenPosts();

        return $post;
    }

    private function gossipPost(PostModel $post, ?string $excludeAddress = null): void
    {
        $this->mesh->broadcast([
            'type' => MessageTypes::POST,
            'post' => $post->toArray(),
        ], $excludeAddress);
    }

    private function pruneSeenPosts(): void
    {
        if (count($this->seenPosts) > $this->seenPostsLimit) {
            $this->seenPosts = array_slice($this->seenPosts, -($this->seenPostsLimit / 2), null, true);
        }
    }
}
