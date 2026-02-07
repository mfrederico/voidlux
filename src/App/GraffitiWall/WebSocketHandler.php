<?php

declare(strict_types=1);

namespace VoidLux\App\GraffitiWall;

use Swoole\WebSocket\Server as WsServer;

/**
 * Tracks connected WebSocket FDs and pushes new posts to browsers in real-time.
 */
class WebSocketHandler
{
    /** @var array<int, true> Connected WebSocket file descriptors */
    private array $fds = [];

    public function __construct(
        private readonly WsServer $server,
    ) {}

    public function onOpen(int $fd): void
    {
        $this->fds[$fd] = true;
    }

    public function onClose(int $fd): void
    {
        unset($this->fds[$fd]);
    }

    /**
     * Push a new post to all connected WebSocket clients.
     */
    public function pushPost(PostModel $post): void
    {
        $json = json_encode([
            'type' => 'new_post',
            'post' => $post->toArray(),
        ]);

        foreach ($this->fds as $fd => $_) {
            if ($this->server->isEstablished($fd)) {
                $this->server->push($fd, $json);
            } else {
                unset($this->fds[$fd]);
            }
        }
    }

    /**
     * Push a status update to all connected clients.
     */
    public function pushStatus(array $status): void
    {
        $json = json_encode([
            'type' => 'status',
            'status' => $status,
        ]);

        foreach ($this->fds as $fd => $_) {
            if ($this->server->isEstablished($fd)) {
                $this->server->push($fd, $json);
            } else {
                unset($this->fds[$fd]);
            }
        }
    }

    public function getConnectionCount(): int
    {
        return count($this->fds);
    }
}
