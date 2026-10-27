<?php

declare(strict_types=1);

namespace ChangeCouncil\Records;

use ChangeCouncil\Support\CanonicalJson;
use RuntimeException;

/**
 * Ed25519 through PHP's built-in sodium. No dependency.
 *
 * key_id = the first 16 hex characters of sha256(public key). A record is
 * verified against a set of public keys by key_id; the signer's own public key
 * is always in that set.
 */
final class RecordSigner
{
    private ?string $secret;

    /** @var array<string, string> key_id => raw 32-byte public key */
    private array $publicKeys = [];

    /** @param array<string, string> $publicKeys key_id => base64 public key */
    public function __construct(?string $secretKeyBase64, array $publicKeys = [])
    {
        $this->secret = null;
        if ($secretKeyBase64 !== null && $secretKeyBase64 !== '') {
            $raw = base64_decode($secretKeyBase64, true);
            if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
                throw new RuntimeException('council.signing.secret_key must be base64 of a 64-byte Ed25519 secret key (php artisan council:keygen).');
            }
            $this->secret = $raw;
            $public = sodium_crypto_sign_publickey_from_secretkey($raw);
            $this->publicKeys[self::keyIdOf($public)] = $public;
        }
        foreach ($publicKeys as $keyId => $b64) {
            $raw = base64_decode((string) $b64, true);
            if ($raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $this->publicKeys[(string) $keyId] = $raw;
            }
        }
    }

    /** @return array{secret: string, public: string, key_id: string} base64 values */
    public static function generate(): array
    {
        $pair = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($pair);

        return [
            'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
            'public' => base64_encode($public),
            'key_id' => self::keyIdOf($public),
        ];
    }

    public static function keyIdOf(string $rawPublicKey): string
    {
        return substr(hash('sha256', $rawPublicKey), 0, 16);
    }

    public function keyId(): string
    {
        return self::keyIdOf($this->publicKey());
    }

    public function publicKey(): string
    {
        if ($this->secret === null) {
            throw new RuntimeException('No signing key configured (council.signing.secret_key).');
        }

        return sodium_crypto_sign_publickey_from_secretkey($this->secret);
    }

    public function signBytes(string $message): string
    {
        if ($this->secret === null) {
            throw new RuntimeException('No signing key configured (council.signing.secret_key).');
        }

        return base64_encode(sodium_crypto_sign_detached($message, $this->secret));
    }

    /** Adds key_id and signature over the canonical form of everything else. */
    public function sign(array $record): array
    {
        unset($record['signature']);
        $record['key_id'] = $this->keyId();
        $record['signature'] = $this->signBytes(CanonicalJson::encode(self::unsigned($record)));

        return $record;
    }

    /** @return array{ok: bool, reason: ?string} */
    public function verify(array $record): array
    {
        $keyId = $record['key_id'] ?? null;
        $signature = $record['signature'] ?? null;
        if (! is_string($keyId) || ! isset($this->publicKeys[$keyId])) {
            return ['ok' => false, 'reason' => 'unknown_key'];
        }
        $raw = is_string($signature) ? base64_decode($signature, true) : false;
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return ['ok' => false, 'reason' => 'bad_signature'];
        }
        try {
            $message = CanonicalJson::encode(self::unsigned($record));
        } catch (\Throwable) {
            return ['ok' => false, 'reason' => 'not_canonical'];
        }

        return sodium_crypto_sign_verify_detached($raw, $message, $this->publicKeys[$keyId])
            ? ['ok' => true, 'reason' => null]
            : ['ok' => false, 'reason' => 'bad_signature'];
    }

    public static function verifyBytes(string $message, string $signatureBase64, string $rawPublicKey): bool
    {
        $raw = base64_decode($signatureBase64, true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($rawPublicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($raw, $message, $rawPublicKey);
    }

    /** The signed content: everything except the signature itself. */
    private static function unsigned(array $record): array
    {
        unset($record['signature']);

        return $record;
    }
}
