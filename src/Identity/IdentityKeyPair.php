<?php

declare(strict_types=1);

namespace VoidLux\Identity;

/**
 * Ed25519 key pair for node identity.
 * Uses libsodium (bundled with PHP 7.2+) for signing and verification.
 */
class IdentityKeyPair
{
    private function __construct(
        public readonly string $publicKey,
        private readonly string $secretKey,
    ) {}

    /**
     * Generate a new random key pair.
     */
    public static function generate(): self
    {
        $kp = sodium_crypto_sign_keypair();
        return new self(
            sodium_crypto_sign_publickey($kp),
            sodium_crypto_sign_secretkey($kp),
        );
    }

    /**
     * Restore a key pair from stored secret key bytes.
     */
    public static function fromSecretKey(string $secretKey): self
    {
        $publicKey = sodium_crypto_sign_publickey_from_secretkey($secretKey);
        return new self($publicKey, $secretKey);
    }

    /**
     * Restore from hex-encoded secret key (for SQLite storage).
     */
    public static function fromHex(string $secretKeyHex): self
    {
        return self::fromSecretKey(sodium_hex2bin($secretKeyHex));
    }

    /**
     * Sign a message, returning the detached signature.
     */
    public function sign(string $message): string
    {
        return sodium_crypto_sign_detached($message, $this->secretKey);
    }

    /**
     * Verify a detached signature against a public key.
     */
    public static function verify(string $message, string $signature, string $publicKey): bool
    {
        try {
            return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
        } catch (\SodiumException) {
            return false;
        }
    }

    public function publicKeyHex(): string
    {
        return sodium_bin2hex($this->publicKey);
    }

    public function secretKeyHex(): string
    {
        return sodium_bin2hex($this->secretKey);
    }
}
