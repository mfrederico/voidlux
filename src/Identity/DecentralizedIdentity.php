<?php

declare(strict_types=1);

namespace VoidLux\Identity;

/**
 * A Decentralized Identifier (DID) bound to a node's cryptographic key pair.
 *
 * DID format: did:voidlux:{node_id}
 *
 * Each node generates a persistent Ed25519 key pair on first boot.
 * The public key is the root of trust — peers verify signatures
 * without any central authority. Credentials issued by one node
 * can be verified by any other node that has received the issuer's
 * public key through the gossip network.
 */
class DecentralizedIdentity
{
    public function __construct(
        public readonly string $did,
        public readonly string $nodeId,
        public readonly string $publicKeyHex,
        public readonly string $role,
        public readonly string $createdAt,
        public readonly int $lamportTs,
    ) {}

    /**
     * Create a new DID for a node.
     */
    public static function create(string $nodeId, string $publicKeyHex, string $role, int $lamportTs): self
    {
        return new self(
            did: "did:voidlux:{$nodeId}",
            nodeId: $nodeId,
            publicKeyHex: $publicKeyHex,
            role: $role,
            createdAt: gmdate('Y-m-d\TH:i:s\Z'),
            lamportTs: $lamportTs,
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            did: $data['did'] ?? '',
            nodeId: $data['node_id'] ?? '',
            publicKeyHex: $data['public_key'] ?? '',
            role: $data['role'] ?? 'worker',
            createdAt: $data['created_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
            lamportTs: (int) ($data['lamport_ts'] ?? 0),
        );
    }

    public function toArray(): array
    {
        return [
            'did' => $this->did,
            'node_id' => $this->nodeId,
            'public_key' => $this->publicKeyHex,
            'role' => $this->role,
            'created_at' => $this->createdAt,
            'lamport_ts' => $this->lamportTs,
        ];
    }

    public function publicKey(): string
    {
        return sodium_hex2bin($this->publicKeyHex);
    }
}
