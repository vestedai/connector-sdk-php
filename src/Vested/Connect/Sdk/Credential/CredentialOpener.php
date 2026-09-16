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
    public const ALG = EnvelopeCipher::ALG;

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
        $version = (int) ($envelope['v'] ?? 1);

        return EnvelopeCipher::open(
            $envelope,
            sprintf('connector:%s|user:%s|v%d', $connectorId, $userId, $version),
            $this->keyring,
        );
    }
}
