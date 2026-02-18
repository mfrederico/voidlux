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
    public const TASK_ASSIGN   = 0x16;
    public const TASK_ARCHIVE  = 0x17;

    // Swarm agent messages
    public const AGENT_REGISTER   = 0x20;
    public const AGENT_HEARTBEAT  = 0x21;
    public const AGENT_DEREGISTER = 0x22;

    // Swarm sync messages
    public const TASK_SYNC_REQ = 0x30;
    public const TASK_SYNC_RSP = 0x31;

    // Leader election messages
    public const EMPEROR_HEARTBEAT = 0x40;
    public const ELECTION_START    = 0x41;
    public const ELECTION_VICTORY  = 0x42;

    // Census / Anti-entropy
    public const CENSUS_REQUEST  = 0x50;
    public const AGENT_SYNC_REQ  = 0x51;
    public const AGENT_SYNC_RSP  = 0x52;

    // Authentication messages
    public const AUTH_CHALLENGE = 0x60;
    public const AUTH_RESPONSE  = 0x61;
    public const AUTH_REJECT    = 0x62;

    // Identity gossip messages
    public const IDENTITY_ANNOUNCE  = 0x70;
    public const CREDENTIAL_ISSUE   = 0x71;
    public const IDENTITY_SYNC_REQ  = 0x72;
    public const IDENTITY_SYNC_RSP  = 0x73;

    // Consensus protocol messages
    public const CONSENSUS_PROPOSE  = 0x80;
    public const CONSENSUS_VOTE     = 0x81;
    public const CONSENSUS_COMMIT   = 0x82;
    public const CONSENSUS_ABORT    = 0x83;
    public const CONSENSUS_SYNC_REQ = 0x84;
    public const CONSENSUS_SYNC_RSP = 0x85;

    // DHT (decentralized storage) messages
    public const DHT_PUT      = 0x90;
    public const DHT_GET      = 0x91;
    public const DHT_GET_RSP  = 0x92;
    public const DHT_DELETE   = 0x93;
    public const DHT_SYNC_REQ = 0x94;
    public const DHT_SYNC_RSP = 0x95;

    // Discovery DHT messages (peer discovery, not storage)
    public const DHT_DISC_LOOKUP     = 0xA0;
    public const DHT_DISC_LOOKUP_RSP = 0xA1;
    public const DHT_DISC_ANNOUNCE   = 0xA2;

    // Swarm node registry messages
    public const SWARM_NODE_REGISTER = 0xB0;
    public const SWARM_NODE_STATUS   = 0xB1;

    // Galactic marketplace messages
    public const OFFERING_ANNOUNCE = 0xC0;
    public const OFFERING_WITHDRAW = 0xC1;
    public const TRIBUTE_REQUEST   = 0xC2;
    public const TRIBUTE_ACCEPT    = 0xC3;
    public const TRIBUTE_REJECT    = 0xC4;

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
        self::TASK_ASSIGN     => 'TASK_ASSIGN',
        self::TASK_ARCHIVE    => 'TASK_ARCHIVE',
        self::AGENT_REGISTER   => 'AGENT_REGISTER',
        self::AGENT_HEARTBEAT  => 'AGENT_HEARTBEAT',
        self::AGENT_DEREGISTER => 'AGENT_DEREGISTER',
        self::TASK_SYNC_REQ   => 'TASK_SYNC_REQ',
        self::TASK_SYNC_RSP   => 'TASK_SYNC_RSP',
        self::EMPEROR_HEARTBEAT => 'EMPEROR_HEARTBEAT',
        self::ELECTION_START    => 'ELECTION_START',
        self::ELECTION_VICTORY  => 'ELECTION_VICTORY',
        self::CENSUS_REQUEST    => 'CENSUS_REQUEST',
        self::AGENT_SYNC_REQ    => 'AGENT_SYNC_REQ',
        self::AGENT_SYNC_RSP    => 'AGENT_SYNC_RSP',
        self::AUTH_CHALLENGE    => 'AUTH_CHALLENGE',
        self::AUTH_RESPONSE     => 'AUTH_RESPONSE',
        self::AUTH_REJECT       => 'AUTH_REJECT',
        self::IDENTITY_ANNOUNCE  => 'IDENTITY_ANNOUNCE',
        self::CREDENTIAL_ISSUE   => 'CREDENTIAL_ISSUE',
        self::IDENTITY_SYNC_REQ  => 'IDENTITY_SYNC_REQ',
        self::IDENTITY_SYNC_RSP  => 'IDENTITY_SYNC_RSP',
        self::CONSENSUS_PROPOSE  => 'CONSENSUS_PROPOSE',
        self::CONSENSUS_VOTE     => 'CONSENSUS_VOTE',
        self::CONSENSUS_COMMIT   => 'CONSENSUS_COMMIT',
        self::CONSENSUS_ABORT    => 'CONSENSUS_ABORT',
        self::CONSENSUS_SYNC_REQ => 'CONSENSUS_SYNC_REQ',
        self::CONSENSUS_SYNC_RSP => 'CONSENSUS_SYNC_RSP',
        self::DHT_PUT      => 'DHT_PUT',
        self::DHT_GET      => 'DHT_GET',
        self::DHT_GET_RSP  => 'DHT_GET_RSP',
        self::DHT_DELETE   => 'DHT_DELETE',
        self::DHT_SYNC_REQ => 'DHT_SYNC_REQ',
        self::DHT_SYNC_RSP => 'DHT_SYNC_RSP',
        self::DHT_DISC_LOOKUP     => 'DHT_DISC_LOOKUP',
        self::DHT_DISC_LOOKUP_RSP => 'DHT_DISC_LOOKUP_RSP',
        self::DHT_DISC_ANNOUNCE   => 'DHT_DISC_ANNOUNCE',
        self::SWARM_NODE_REGISTER => 'SWARM_NODE_REGISTER',
        self::SWARM_NODE_STATUS   => 'SWARM_NODE_STATUS',
        self::OFFERING_ANNOUNCE => 'OFFERING_ANNOUNCE',
        self::OFFERING_WITHDRAW => 'OFFERING_WITHDRAW',
        self::TRIBUTE_REQUEST   => 'TRIBUTE_REQUEST',
        self::TRIBUTE_ACCEPT    => 'TRIBUTE_ACCEPT',
        self::TRIBUTE_REJECT    => 'TRIBUTE_REJECT',
    ];

    public static function name(int $type): string
    {
        return self::NAMES[$type] ?? "UNKNOWN({$type})";
    }
}
