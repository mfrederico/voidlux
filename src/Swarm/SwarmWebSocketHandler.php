<?php

declare(strict_types=1);

namespace VoidLux\Swarm;

use Swoole\WebSocket\Server as WsServer;

/**
 * WebSocket handler for real-time swarm dashboard updates.
 */
class SwarmWebSocketHandler
{
    /** @var array<int, true> */
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

    public function pushTaskEvent(string $event, array $data): void
    {
        $this->broadcast([
            'type' => 'task_event',
            'event' => $event,
            'data' => $data,
        ]);
    }

    public function pushAgentEvent(string $event, array $data): void
    {
        $this->broadcast([
            'type' => 'agent_event',
            'event' => $event,
            'data' => $data,
        ]);
    }

    public function pushStatus(array $status): void
    {
        $this->broadcast([
            'type' => 'status',
            'status' => $status,
        ]);
    }

    private function broadcast(array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

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
