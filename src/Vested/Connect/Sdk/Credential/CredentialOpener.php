<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Credential;

/**
 * Opens a sealed user-credential envelope.
 *
 * The core seals with this connector's public key and cannot open what it
 * stored; this class is the only place the plaintext exists. Connector authors
 * never touch it directly — the SDK calls it from the tool context, which is
 * what makes the identity check below impossible to skip by accident.
 *
 * Format: ECDH-P256 -> HKDF-SHA256 -> AES-256-GCM. See
 * docs/superpowers/specs/2026-08-10-connector-user-auth-design.md.
 */
final class CredentialOpener
{
    public const ALG = 'ECDH-P256+HKDF-SHA256+A256GCM';

    private const INFO = 'vested-connector-credential-v1';
    private const TAG_BYTES = 16;

    /** @var list<string> PKCS#8 PEM private keys, newest first. */
    private array $keyring;

    public function __construct(string ...$privateKeyPems)
    {
        $this->keyring = array_values($privateKeyPems);
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array<string, string>
     */
    public function open(array $envelope, string $connectorId, string $userId): array
    {
        $alg = (string) ($envelope['alg'] ?? '');
        if ($alg !== self::ALG) {
            throw CredentialException::unsupportedAlg($alg);
        }

        // Verify the binding BEFORE decrypting. The AAD is also enforced
        // cryptographically by GCM below, but checking it here turns a generic
        // "decrypt failed" into a specific, alertable security signal.
        $version  = (int) ($envelope['v'] ?? 1);
        $expected = sprintf('connector:%s|user:%s|v%d', $connectorId, $userId, $version);
        $actual   = (string) ($envelope['aad'] ?? '');
        if (! hash_equals($expected, $actual)) {
            throw CredentialException::identityMismatch($expected, $actual);
        }

        $epkDer = base64_decode((string) ($envelope['epk'] ?? ''), true);
        $iv     = base64_decode((string) ($envelope['iv'] ?? ''), true);
        $raw    = base64_decode((string) ($envelope['ct'] ?? ''), true);

        if ($epkDer === false || $iv === false || $raw === false || strlen($raw) <= self::TAG_BYTES) {
            throw CredentialException::decryptFailed('credential envelope is malformed');
        }

        $ephemeral = openssl_pkey_get_public(
            "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($epkDer), 64, "\n")
            . "-----END PUBLIC KEY-----\n"
        );
        if ($ephemeral === false) {
            throw CredentialException::decryptFailed('ephemeral public key is not importable');
        }

        $ct  = substr($raw, 0, -self::TAG_BYTES);
        $tag = substr($raw, -self::TAG_BYTES);

        foreach ($this->keyring as $pem) {
            $private = openssl_pkey_get_private($pem);
            if ($private === false) {
                continue;
            }

            $z = @openssl_pkey_derive($ephemeral, $private, 32);
            if ($z === false) {
                continue;
            }

            $key = hash_hkdf('sha256', $z, 32, self::INFO, str_repeat("\0", 32));
            $pt  = @openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $actual);
            if ($pt === false) {
                continue; // wrong key in the ring, or authentication failed
            }

            /** @var array<string, string>|null $decoded */
            $decoded = json_decode($pt, true);
            if (! is_array($decoded)) {
                throw CredentialException::decryptFailed('credential payload is not an object');
            }

            return $decoded;
        }

        throw CredentialException::decryptFailed(
            'credential envelope failed to decrypt or authenticate under any key in the ring'
        );
    }
}
