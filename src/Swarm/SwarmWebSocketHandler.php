<?php

declare(strict_types=1);

namespace VoidLux\Swarm;

use Swoole\WebSocket\Server as WsServer;

/**
 * WebSocket handler for real-time swarm dashboard updates.
 *
 * Pushes full state on connect and incremental updates on events.
 * The dashboard renders entirely from WS-pushed data — no HTTP polling.
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

    /**
     * Send full state to a single client (on WS connect/reconnect).
     * @param array<array> $tasks Array of task toArray() objects
     * @param array<array> $agents Array of agent toArray() objects
     */
    public function pushFullState(int $fd, array $tasks, array $agents, array $status): void
    {
        $this->pushTo($fd, [
            'type' => 'full_state',
            'tasks' => $tasks,
            'agents' => $agents,
            'status' => $status,
        ]);
    }

    /**
     * Broadcast a full task object on any task state change.
     */
    public function pushTaskUpdate(string $event, array $taskData): void
    {
        $this->broadcast([
            'type' => 'task_update',
            'event' => $event,
            'task' => $taskData,
        ]);
    }

    /**
     * Broadcast a full agent object on any agent state change.
     */
    public function pushAgentUpdate(string $event, array $agentData): void
    {
        $this->broadcast([
            'type' => 'agent_update',
            'event' => $event,
            'agent' => $agentData,
        ]);
    }

    /**
     * Broadcast agent removal.
     */
    public function pushAgentRemoved(string $agentId): void
    {
        $this->broadcast([
            'type' => 'agent_removed',
            'agent_id' => $agentId,
        ]);
    }

    /**
     * Broadcast a board message event.
     */
    public function pushBoardMessage(string $event, array $messageData): void
    {
        $this->broadcast([
            'type' => 'board_message',
            'event' => $event,
            'message' => $messageData,
        ]);
    }

    /**
     * Broadcast board message removal.
     */
    public function pushBoardMessageRemoved(string $messageId): void
    {
        $this->broadcast([
            'type' => 'board_message_removed',
            'message_id' => $messageId,
        ]);
    }

    /**
     * Broadcast emperor/election status updates.
     */
    public function pushStatus(array $status): void
    {
        $this->broadcast([
            'type' => 'status',
            'status' => $status,
        ]);
    }

    /**
     * Broadcast a forum message to all connected clients.
     */
    public function pushForumMessage(string $channelId, array $messageData): void
    {
        $this->broadcast([
            'type' => 'forum_message',
            'channel_id' => $channelId,
            'message' => $messageData,
        ]);
    }

    /**
     * Broadcast vote tally update for a channel.
     */
    public function pushForumVoteUpdate(string $channelId, array $voteTally): void
    {
        $this->broadcast([
            'type' => 'forum_vote_update',
            'channel_id' => $channelId,
            'tally' => $voteTally,
        ]);
    }

    /**
     * Broadcast channel creation event.
     */
    public function pushForumChannelCreated(string $channelId, string $taskTitle): void
    {
        $this->broadcast([
            'type' => 'forum_channel_created',
            'channel_id' => $channelId,
            'task_title' => $taskTitle,
        ]);
    }

    /**
     * Broadcast channel resolution event.
     */
    public function pushForumChannelResolved(string $channelId, string $outcome): void
    {
        $this->broadcast([
            'type' => 'forum_channel_resolved',
            'channel_id' => $channelId,
            'outcome' => $outcome,
        ]);
    }

    private function pushTo(int $fd, array $payload): void
    {
        if ($this->server->isEstablished($fd)) {
            $this->server->push($fd, json_encode($payload, JSON_UNESCAPED_SLASHES));
        }
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
