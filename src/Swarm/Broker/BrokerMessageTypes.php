<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Broker;

/**
 * Wire protocol message types for the Seneschal-to-Seneschal broker mesh.
 *
 * These are used on the dedicated broker TcpMesh (separate from
 * the intra-swarm P2P mesh), so the constant values don't collide
 * with MessageTypes — they live in a different protocol space.
 */
class BrokerMessageTypes
{
    public const HELLO    = 0x01;
    public const RELAY    = 0x02;
    public const SYNC_REQ = 0x03;
    public const SYNC_RSP = 0x04;
    public const PING     = 0x05;
    public const PONG     = 0x06;

    public const NAMES = [
        self::HELLO    => 'BROKER_HELLO',
        self::RELAY    => 'BROKER_RELAY',
        self::SYNC_REQ => 'BROKER_SYNC_REQ',
        self::SYNC_RSP => 'BROKER_SYNC_RSP',
        self::PING     => 'BROKER_PING',
        self::PONG     => 'BROKER_PONG',
    ];

    public static function name(int $type): string
    {
        return self::NAMES[$type] ?? "BROKER_UNKNOWN({$type})";
    }
}
