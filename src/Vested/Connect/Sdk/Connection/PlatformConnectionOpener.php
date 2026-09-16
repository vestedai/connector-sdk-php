<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Connection;

use Vested\Connect\Sdk\Credential\CredentialException;
use Vested\Connect\Sdk\Credential\EnvelopeCipher;

/**
 * Opens a tenant connection the platform sealed to this connector (SaaS
 * Provided Connectors, spec §4.4).
 *
 * The AAD binds the envelope to THIS platform connector, the calling
 * organization and one connection, so an envelope captured from one tenant's
 * call cannot be replayed into another tenant's, or swapped for another of the
 * same tenant's connections. The platform connector id is the one the Hub put
 * in HelloAck; the organization comes from the ToolCallRequest itself.
 */
final class PlatformConnectionOpener
{
    /** @var list<string> */
    private array $keyring;

    public function __construct(string ...$privateKeyPems)
    {
        $this->keyring = array_values($privateKeyPems);
    }

    public static function aad(string $platformConnectorId, string $organizationId, string $connectionId, int $version = 1): string
    {
        return sprintf('platform:%s|org:%s|connection:%s|v%d', $platformConnectorId, $organizationId, $connectionId, $version);
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public function open(array $envelope, string $platformConnectorId, string $organizationId, string $connectionId): array
    {
        if ($platformConnectorId === '' || $organizationId === '' || $connectionId === '') {
            throw CredentialException::decryptFailed(
                'a platform connection cannot be opened without the connector, organization and connection it is bound to'
            );
        }

        $version = (int) ($envelope['v'] ?? 1);

        return EnvelopeCipher::open(
            $envelope,
            self::aad($platformConnectorId, $organizationId, $connectionId, $version),
            $this->keyring,
        );
    }
}
