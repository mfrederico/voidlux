<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Auth;

use Swoole\Coroutine;
use Swoole\WebSocket\Server as WsServer;

/**
 * PTY-based terminal streaming using tmux attach.
 *
 * This creates a proper pseudo-terminal and runs `tmux attach -t session`,
 * then streams the I/O over WebSocket. This is how professional terminal
 * web applications work (like gotty, wetty, etc.)
 */
class PtyTerminalStream
{
    private $process = null;
    private $pipes = [];
    private bool $running = false;
    private int $rows = 30;
    private int $cols = 120;

    public function __construct(
        private readonly string $sessionName,
    ) {}

    /**
     * Start streaming the terminal to WebSocket.
     */
    public function start(WsServer $server, int $fd): void
    {
        // Use script command to create a pty and run tmux attach
        // The -q flag suppresses script's own output
        // The -c flag runs the command
        // /dev/null discards the typescript file
        $cmd = sprintf(
            'script -qfc %s /dev/null',
            escapeshellarg('tmux attach -t ' . $this->sessionName)
        );

        // Create process with pipes for stdin/stdout/stderr
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $this->process = proc_open($cmd, $descriptors, $this->pipes, null, [
            'TERM' => 'xterm-256color',
            'LINES' => (string) $this->rows,
            'COLUMNS' => (string) $this->cols,
        ]);

        if (!is_resource($this->process)) {
            $server->push($fd, "Error: Failed to start terminal process\r\n");
            $server->close($fd);
            return;
        }

        // Check if process started successfully
        $status = proc_get_status($this->process);
        if (!$status['running']) {
            $stderr = stream_get_contents($this->pipes[2]);
            $server->push($fd, "Error: Terminal process failed to start: {$stderr}\r\n");
            $server->close($fd);
            return;
        }

        // Set streams to non-blocking mode
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);

        $this->running = true;

        // Stream stdout to WebSocket
        Coroutine::create(function () use ($server, $fd) {
            while ($this->running && is_resource($this->pipes[1])) {
                $data = fread($this->pipes[1], 8192);
                if ($data !== false && $data !== '') {
                    $server->push($fd, $data);
                }

                // Check if process is still running
                $status = proc_get_status($this->process);
                if (!$status['running']) {
                    $this->running = false;
                    $server->push($fd, "\r\n\r\n[Session terminated]\r\n");
                    $server->close($fd);
                    break;
                }

                Coroutine::usleep(20000); // 20ms poll interval
            }
        });

        // Stream stderr to WebSocket (errors)
        Coroutine::create(function () use ($server, $fd) {
            while ($this->running && is_resource($this->pipes[2])) {
                $data = fread($this->pipes[2], 8192);
                if ($data !== false && $data !== '') {
                    $server->push($fd, $data);
                }
                Coroutine::usleep(20000); // 20ms
            }
        });
    }

    /**
     * Send keyboard input to the terminal.
     */
    public function sendInput(string $data): void
    {
        if ($this->running && is_resource($this->pipes[0])) {
            fwrite($this->pipes[0], $data);
            fflush($this->pipes[0]);
        }
    }

    /**
     * Resize the terminal (sends SIGWINCH to the process).
     */
    public function resize(int $rows, int $cols): void
    {
        $this->rows = $rows;
        $this->cols = $cols;

        // Get the PID of the script process
        $status = proc_get_status($this->process);
        if ($status['running']) {
            $pid = $status['pid'];

            // First resize the tmux pane
            exec(sprintf(
                'tmux resize-pane -t %s -x %d -y %d 2>/dev/null',
                escapeshellarg($this->sessionName),
                $cols,
                $rows
            ));

            // Send SIGWINCH to the process to notify of size change
            // The script process will propagate this to tmux attach
            posix_kill($pid, SIGWINCH);
        }
    }

    /**
     * Stop the terminal stream and clean up.
     */
    public function stop(): void
    {
        $this->running = false;

        // Close pipes
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        // Terminate process
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }
}
