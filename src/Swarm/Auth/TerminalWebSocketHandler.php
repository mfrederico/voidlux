<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Auth;

use Swoole\Coroutine;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WsServer;
use Aoe\Tmux\TmuxService;

/**
 * WebSocket handler for streaming tmux terminal output to browser via pty.
 */
class TerminalWebSocketHandler
{
    private array $sessions = []; // fd => ['session_name' => '', 'stream' => PtyTerminalStream]

    public function __construct(
        private readonly TmuxService $tmux,
    ) {}

    /**
     * Handle new WebSocket connection for a terminal session.
     */
    public function onOpen(WsServer $server, int $fd, string $sessionName): void
    {
        // Verify session exists
        if (!$this->tmux->sessionExistsByName($sessionName)) {
            $server->push($fd, "Error: Session '{$sessionName}' not found\r\n");
            $server->close($fd);
            return;
        }

        // Create pty-based terminal stream
        $stream = new PtyTerminalStream($sessionName);

        $this->sessions[$fd] = [
            'session_name' => $sessionName,
            'stream' => $stream,
        ];

        // Start streaming immediately
        $stream->start($server, $fd);
    }

    /**
     * Handle incoming data from browser (keystrokes or control messages).
     */
    public function onMessage(WsServer $server, int $fd, Frame $frame): void
    {
        $session = $this->sessions[$fd] ?? null;
        if (!$session) {
            return;
        }

        $stream = $session['stream'];
        $data = $frame->data;

        // Check if this is a JSON control message
        $decoded = json_decode($data, true);
        if ($decoded && isset($decoded['type'])) {
            if ($decoded['type'] === 'resize' && isset($decoded['rows']) && isset($decoded['cols'])) {
                // Resize the pty terminal
                $rows = (int) $decoded['rows'];
                $cols = (int) $decoded['cols'];
                $stream->resize($rows, $cols);
                return;
            }
        }

        // Regular keystroke data - send to pty stdin
        $stream->sendInput($data);
    }

    /**
     * Check if this handler owns a connection.
     */
    public function hasConnection(int $fd): bool
    {
        return isset($this->sessions[$fd]);
    }

    /**
     * Handle WebSocket close.
     */
    public function onClose(int $fd): void
    {
        if (isset($this->sessions[$fd])) {
            $this->sessions[$fd]['stream']->stop();
            unset($this->sessions[$fd]);
        }
    }

}
