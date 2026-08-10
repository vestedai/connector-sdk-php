<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Credential;

/**
 * Lazily opens the caller's sealed credential for one tool invocation.
 *
 * Exists as its own object so {@see \Vested\Connect\Sdk\Tool\ToolContext} can
 * stay `readonly` while still memoizing: a readonly property may hold a
 * reference to a mutable object, and decrypting on every access would redo an
 * ECDH key agreement per call.
 *
 * Lazy rather than eager because most tools never read the credential, and a
 * tool that doesn't ask should not pay for a decrypt — nor fail because of one.
 */
final class CredentialResolver
{
    /** @var array<string, string>|null */
    private ?array $opened = null;

    private bool $attempted = false;

    public function __construct(
        private readonly ?CredentialOpener $opener,
        private readonly string $envelopeJson,
        private readonly string $connectorId,
        private readonly string $userId,
    ) {}

    public function hasCredential(): bool
    {
        return $this->opener !== null && $this->envelopeJson !== '';
    }

    /**
     * @return array<string, string>
     *
     * @throws CredentialUnavailable  no credential was forwarded for this call
     * @throws CredentialException    the envelope is not ours to open, or is corrupt
     */
    public function credential(): array
    {
        if ($this->attempted && $this->opened !== null) {
            return $this->opened;
        }
        $this->attempted = true;

        if (! $this->hasCredential()) {
            throw new CredentialUnavailable(
                'No user credential was supplied for this tool call. Either this connector '
                . 'declares no credential schema, or the platform refused the call before dispatch.'
            );
        }

        /** @var array<string, mixed>|null $envelope */
        $envelope = json_decode($this->envelopeJson, true);
        if (! is_array($envelope)) {
            throw CredentialException::decryptFailed('The forwarded credential envelope is malformed.');
        }

        // The AAD identity check happens inside open(). It is deliberately NOT
        // duplicated here: one implementation, on the only path a connector
        // author can reach.
        $this->opened = $this->opener?->open($envelope, $this->connectorId, $this->userId) ?? [];

        return $this->opened;
    }
}
