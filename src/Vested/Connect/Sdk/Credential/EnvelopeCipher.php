<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Credential;

/**
 * Decrypts a sealed envelope once its identity has been named.
 *
 * Format: ECDH-P256 -> HKDF-SHA256 -> AES-256-GCM, pinned by
 * vested-ai-sdks/testdata/credential-envelope-vectors.json. The per-user
 * credential courier and SaaS platform connections share it and differ ONLY in
 * the AAD they demand, which is why the demand is a parameter here and the
 * formats live in CredentialOpener and PlatformConnectionOpener.
 */
final class EnvelopeCipher
{
    public const ALG = 'ECDH-P256+HKDF-SHA256+A256GCM';

    private const INFO = 'vested-connector-credential-v1';

    private const TAG_BYTES = 16;

    /**
     * @param  array<string, mixed>  $envelope
     * @param  list<string>  $keyring  PKCS#8 PEM private keys, newest first
     * @return array<string, mixed>
     */
    public static function open(array $envelope, string $expectedAad, array $keyring): array
    {
        $alg = (string) ($envelope['alg'] ?? '');
        if ($alg !== self::ALG) {
            throw CredentialException::unsupportedAlg($alg);
        }

        // Verify the binding BEFORE decrypting. GCM also enforces the AAD, but
        // checking it here turns a generic "decrypt failed" into a specific,
        // alertable security signal.
        $actual = (string) ($envelope['aad'] ?? '');
        if (! hash_equals($expectedAad, $actual)) {
            throw CredentialException::identityMismatch($expectedAad, $actual);
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

        foreach ($keyring as $pem) {
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
