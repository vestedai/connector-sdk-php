<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Connection;

/**
 * One tenant connection supplied with a tool call (SaaS Provided Connectors).
 *
 * The resource is plain data. The credential stays sealed until credential() is
 * called, and is opened at most once per call — only after its AAD names this
 * connector, the calling organization and this connection.
 *
 * For an oauth2 profile the credential is ['access_token', 'token_type',
 * 'expires_at']: a short-lived token the platform refreshes, never a refresh
 * token. For a fields profile it is the declared field map.
 */
final class Connection
{
    /** @var array<string, mixed>|null */
    private ?array $opened = null;

    /** @param array<string, mixed> $resource */
    public function __construct(
        public readonly string $profile,
        public readonly string $label,
        public readonly string $connectionId,
        private readonly array $resource,
        private readonly string $envelopeJson,
        private readonly ?PlatformConnectionOpener $opener,
        private readonly string $platformConnectorId,
        private readonly string $organizationId,
    ) {}

    /** @return array<string, mixed> e.g. ['id' => 'properties/1', 'label' => 'Main', 'meta' => ['currency' => 'SAR']] */
    public function resource(): array
    {
        return $this->resource;
    }

    /** @return array<string, mixed> */
    public function credential(): array
    {
        if ($this->opened !== null) {
            return $this->opened;
        }

        if ($this->opener === null) {
            throw new ConnectionUnavailable(
                'This worker has no private key for tenant connections. Set VESTED_CREDENTIAL_PRIVATE_KEY '
                . 'or VESTED_CREDENTIAL_PRIVATE_KEY_FILE.'
            );
        }

        $envelope = json_decode($this->envelopeJson, true);
        if (! is_array($envelope)) {
            throw new ConnectionUnavailable("The '{$this->profile}' connection arrived malformed.");
        }

        return $this->opened = $this->opener->open($envelope, $this->platformConnectorId, $this->organizationId, $this->connectionId);
    }
}
