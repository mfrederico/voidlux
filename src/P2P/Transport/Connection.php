<?php

declare(strict_types=1);

namespace VoidLux\P2P\Transport;

use VoidLux\P2P\Protocol\MessageCodec;

/**
 * Wraps a single peer TCP connection (Swoole coroutine client or accepted socket).
 */
class Connection
{
    private string $buffer = '';
    private bool $closed = false;
    private ?string $peerId = null;
    private float $lastActivity;

    public function __construct(
        private readonly mixed $socket, // Swoole\Coroutine\Client or Swoole\Coroutine\Socket
        public readonly string $remoteHost,
        public readonly int $remotePort,
        public readonly bool $inbound,
    ) {
        $this->lastActivity = microtime(true);
    }

    public function getPeerId(): ?string
    {
        return $this->peerId;
    }

    public function setPeerId(string $id): void
    {
        $this->peerId = $id;
    }

    public function send(array $message): bool
    {
        if ($this->closed) {
            return false;
        }

        try {
            $data = MessageCodec::encode($message);
            if ($this->socket instanceof \Swoole\Coroutine\Socket) {
                $written = $this->socket->sendAll($data);
                return $written === strlen($data);
            }
            return $this->socket->send($data) !== false;
        } catch (\Throwable) {
            $this->closed = true;
            return false;
        }
    }

    /**
     * Receive one message. Blocks in coroutine context until data arrives.
     * Returns null on timeout or connection close.
     */
    public function receive(float $timeout = 5.0): ?array
    {
        if ($this->closed) {
            return null;
        }

        // Try to decode from existing buffer first
        $result = MessageCodec::decode($this->buffer);
        if ($result !== null) {
            [$message, $consumed] = $result;
            $this->buffer = substr($this->buffer, $consumed);
            $this->lastActivity = microtime(true);
            return $message;
        }

        // Read more data
        try {
            if ($this->socket instanceof \Swoole\Coroutine\Socket) {
                $data = $this->socket->recv(65536, $timeout);
            } else {
                $data = $this->socket->recv(65536, $timeout);
            }
        } catch (\Throwable) {
            $this->closed = true;
            return null;
        }

        if ($data === false || $data === '') {
            // recv returns false on timeout — that's not a disconnect
            if ($this->socket instanceof \Swoole\Coroutine\Socket && $this->socket->errCode === SOCKET_ETIMEDOUT) {
                return null;
            }
            $this->closed = true;
            return null;
        }

        $this->buffer .= $data;
        $this->lastActivity = microtime(true);

        $result = MessageCodec::decode($this->buffer);
        if ($result !== null) {
            [$message, $consumed] = $result;
            $this->buffer = substr($this->buffer, $consumed);
            return $message;
        }

        return null;
    }

    /**
     * Receive all complete messages currently buffered + one read.
     * @return array[]
     */
    public function receiveAll(float $timeout = 0.1): array
    {
        if ($this->closed) {
            return [];
        }

        try {
            if ($this->socket instanceof \Swoole\Coroutine\Socket) {
                $data = $this->socket->recv(65536, $timeout);
            } else {
                $data = $this->socket->recv(65536, $timeout);
            }
            if ($data !== false && $data !== '') {
                $this->buffer .= $data;
                $this->lastActivity = microtime(true);
            }
        } catch (\Throwable) {
            // OK, just decode what we have
        }

        [$messages, $this->buffer] = MessageCodec::decodeAll($this->buffer);
        return $messages;
    }

    public function close(): void
    {
        if (!$this->closed) {
            $this->closed = true;
            try {
                $this->socket->close();
            } catch (\Throwable) {
            }
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function getLastActivity(): float
    {
        return $this->lastActivity;
    }

    public function address(): string
    {
        return "{$this->remoteHost}:{$this->remotePort}";
    }
}
