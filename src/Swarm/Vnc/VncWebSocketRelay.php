<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Vnc;

use Swoole\Coroutine;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

/**
 * WebSocket-to-TCP relay for VNC connections.
 *
 * Replaces external websockify (Python) with native OpenSwoole coroutine relay.
 * Browser (noVNC) connects via WebSocket, relay bridges to x11vnc TCP port.
 *
 * Route: /vnc-ws/{vncPort}
 */
class VncWebSocketRelay
{
    /** @var array<int, \Swoole\Coroutine\Client> browser fd => TCP client to x11vnc */
    private array $connections = [];

    /** @var array<int, bool> track which fds are VNC relay connections */
    private array $vncFds = [];

    public function isVncConnection(int $fd): bool
    {
        return isset($this->vncFds[$fd]);
    }

    public function handleOpen(Server $server, int $fd, int $vncPort): void
    {
        $this->vncFds[$fd] = true;

        $tcp = new \Swoole\Coroutine\Client(SWOOLE_SOCK_TCP);
        if (!$tcp->connect('127.0.0.1', $vncPort, 5.0)) {
            error_log("VncRelay: failed to connect to x11vnc on port {$vncPort}");
            if ($server->isEstablished($fd)) {
                $server->disconnect($fd, 1011, "Cannot connect to VNC port {$vncPort}");
            }
            unset($this->vncFds[$fd]);
            return;
        }

        $this->connections[$fd] = $tcp;

        // Coroutine: TCP -> WS (VNC server sends data to browser)
        Coroutine::create(function () use ($server, $fd, $tcp) {
            while ($tcp->connected && $server->isEstablished($fd)) {
                $data = $tcp->recv(65536, 1.0);
                if ($data === false || $data === '') {
                    if ($tcp->errCode === SWOOLE_ERROR_CO_TIMEDOUT) {
                        continue;
                    }
                    break;
                }
                if ($server->isEstablished($fd)) {
                    $server->push($fd, $data, WEBSOCKET_OPCODE_BINARY);
                }
            }

            // Clean up when TCP side disconnects
            $tcp->close();
            if ($server->isEstablished($fd)) {
                $server->disconnect($fd);
            }
            unset($this->connections[$fd]);
            unset($this->vncFds[$fd]);
        });
    }

    public function handleMessage(Server $server, Frame $frame): void
    {
        $tcp = $this->connections[$frame->fd] ?? null;
        if ($tcp && $tcp->connected) {
            $tcp->send($frame->data);
        }
    }

    public function handleClose(int $fd): void
    {
        if (isset($this->connections[$fd])) {
            $this->connections[$fd]->close();
            unset($this->connections[$fd]);
        }
        unset($this->vncFds[$fd]);
    }
}
