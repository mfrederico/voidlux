<?php

declare(strict_types=1);

namespace VoidLux\Identity;

/**
 * A verifiable credential issued by one node about another.
 *
 * Credentials are the building blocks of the trust network.
 * Any node can issue a credential (e.g., "node X is a trusted worker"),
 * and any node can verify it using the issuer's public key.
 *
 * No central authority is required — trust is transitive through
 * the credential chain propagated via gossip.
 */
class Credential
{
    public function __construct(
        public readonly string $id,
        public readonly string $issuerDid,
        public readonly string $subjectDid,
        public readonly string $type,
        public readonly array $claims,
        public readonly string $signatureHex,
        public readonly string $issuedAt,
        public readonly string $expiresAt,
        public readonly int $lamportTs,
    ) {}

    /**
     * Issue a new credential, signed by the issuer's key pair.
     */
    public static function issue(
        IdentityKeyPair $issuerKeys,
        string $issuerDid,
        string $subjectDid,
        string $type,
        array $claims,
        int $lamportTs,
        int $ttlSeconds = 86400,
    ): self {
        $id = bin2hex(random_bytes(16));
        $issuedAt = gmdate('Y-m-d\TH:i:s\Z');
        $expiresAt = gmdate('Y-m-d\TH:i:s\Z', time() + $ttlSeconds);

        $payload = self::buildSignPayload($id, $issuerDid, $subjectDid, $type, $claims, $issuedAt, $expiresAt);
        $signature = $issuerKeys->sign($payload);

        return new self(
            id: $id,
            issuerDid: $issuerDid,
            subjectDid: $subjectDid,
            type: $type,
            claims: $claims,
            signatureHex: sodium_bin2hex($signature),
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
            lamportTs: $lamportTs,
        );
    }

    /**
     * Verify the credential's signature against the issuer's public key.
     */
    public function verify(string $issuerPublicKey): bool
    {
        $payload = self::buildSignPayload(
            $this->id, $this->issuerDid, $this->subjectDid,
            $this->type, $this->claims, $this->issuedAt, $this->expiresAt,
        );

        try {
            $signature = sodium_hex2bin($this->signatureHex);
        } catch (\SodiumException) {
            return false;
        }

        return IdentityKeyPair::verify($payload, $signature, $issuerPublicKey);
    }

    public function isExpired(): bool
    {
        return strtotime($this->expiresAt) < time();
    }

    private static function buildSignPayload(
        string $id,
        string $issuerDid,
        string $subjectDid,
        string $type,
        array $claims,
        string $issuedAt,
        string $expiresAt,
    ): string {
        return json_encode([
            'id' => $id,
            'issuer' => $issuerDid,
            'subject' => $subjectDid,
            'type' => $type,
            'claims' => $claims,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
        ], JSON_UNESCAPED_SLASHES);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? '',
            issuerDid: $data['issuer_did'] ?? '',
            subjectDid: $data['subject_did'] ?? '',
            type: $data['type'] ?? '',
            claims: is_string($data['claims'] ?? null) ? json_decode($data['claims'], true) : ($data['claims'] ?? []),
            signatureHex: $data['signature'] ?? '',
            issuedAt: $data['issued_at'] ?? '',
            expiresAt: $data['expires_at'] ?? '',
            lamportTs: (int) ($data['lamport_ts'] ?? 0),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'issuer_did' => $this->issuerDid,
            'subject_did' => $this->subjectDid,
            'type' => $this->type,
            'claims' => $this->claims,
            'signature' => $this->signatureHex,
            'issued_at' => $this->issuedAt,
            'expires_at' => $this->expiresAt,
            'lamport_ts' => $this->lamportTs,
        ];
    }
}
