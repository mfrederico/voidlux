<?php

declare(strict_types=1);

namespace VoidLux\P2P\Protocol;

/**
 * Wire protocol message type constants.
 */
class MessageTypes
{
    // Core P2P messages
    public const HELLO    = 0x01;
    public const POST     = 0x02;
    public const SYNC_REQ = 0x03;
    public const SYNC_RSP = 0x04;
    public const PEX      = 0x05;
    public const PING     = 0x06;
    public const PONG     = 0x07;

    // Swarm task messages
    public const TASK_CREATE   = 0x10;
    public const TASK_CLAIM    = 0x11;
    public const TASK_UPDATE   = 0x12;
    public const TASK_COMPLETE = 0x13;
    public const TASK_FAIL     = 0x14;
    public const TASK_CANCEL   = 0x15;

    // Swarm agent messages
    public const AGENT_REGISTER  = 0x20;
    public const AGENT_HEARTBEAT = 0x21;

    // Swarm sync messages
    public const TASK_SYNC_REQ = 0x30;
    public const TASK_SYNC_RSP = 0x31;

    public const NAMES = [
        self::HELLO    => 'HELLO',
        self::POST     => 'POST',
        self::SYNC_REQ => 'SYNC_REQ',
        self::SYNC_RSP => 'SYNC_RSP',
        self::PEX      => 'PEX',
        self::PING     => 'PING',
        self::PONG     => 'PONG',
        self::TASK_CREATE     => 'TASK_CREATE',
        self::TASK_CLAIM      => 'TASK_CLAIM',
        self::TASK_UPDATE     => 'TASK_UPDATE',
        self::TASK_COMPLETE   => 'TASK_COMPLETE',
        self::TASK_FAIL       => 'TASK_FAIL',
        self::TASK_CANCEL     => 'TASK_CANCEL',
        self::AGENT_REGISTER  => 'AGENT_REGISTER',
        self::AGENT_HEARTBEAT => 'AGENT_HEARTBEAT',
        self::TASK_SYNC_REQ   => 'TASK_SYNC_REQ',
        self::TASK_SYNC_RSP   => 'TASK_SYNC_RSP',
    ];

    public static function name(int $type): string
    {
        return self::NAMES[$type] ?? "UNKNOWN({$type})";
    }
}
