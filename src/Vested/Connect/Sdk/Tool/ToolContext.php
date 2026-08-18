<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tool;

use DateTimeImmutable;
use Vested\Connect\Sdk\Credential\CredentialResolver;
use Vested\Connect\Sdk\Credential\CredentialUnavailable;
use Psr\Log\LoggerInterface;

/**
 * Value object passed to every tool handler. Carries the per-invocation
 * identity context the hub provides plus a logger pre-bound with
 * invocation_id, agent_key, and tool_key fields.
 */
final readonly class ToolContext
{
    /**
     * @param list<string> $erpDepartmentIdentifiers ERP identifier of each department
     *        the calling user belongs to in the run's org. Empty list when unset.
     */
    public function __construct(
        public string            $invocationId,
        public string            $organizationId,
        public string            $userId,
        public string            $userEmail,
        public string            $conversationId,
        public string            $agentKey,
        public string            $toolKey,
        public int               $deadlineMs,
        public LoggerInterface   $logger,
        public DateTimeImmutable $invokedAt,
        /** Calling user's ERP/HR employee number. Empty string when unset. */
        public string            $employeeNo = '',
        /** Calling user's ERP identifier. Empty string when unset. */
        public string            $erpIdentifier = '',
        /** @var list<string> ERP identifiers of the calling user's departments. Empty array when unset. */
        public array             $erpDepartmentIdentifiers = [],
        /** Null for connectors that declare no credential schema. */
        private ?CredentialResolver $credentials = null,
        /**
         * What the core's SQL gate resolved for this call, or null when the
         * core sent none.
         *
         * ⚠ NULL IS NOT AN EMPTY TABLE LIST. Null means the core had nothing
         * to tell you — this connector declares no relational source, its
         * gate is `off`, or this is not the governed query tool. It NEVER
         * means "no tables were touched". A present {@see SchemaContext}
         * with an empty `tables` array is a different claim: the gate
         * decided and resolved nothing. A handler that treats null as empty
         * builds a permission check that silently approves everything.
         */
        public ?SchemaContext $schemaContext = null,
    ) {}

    /**
     * True when the platform forwarded a sealed credential for this caller.
     *
     * False for a connector that declares no credential schema — such a tool
     * should not be asking for one.
     */
    public function hasCredential(): bool
    {
        return $this->credentials?->hasCredential() ?? false;
    }

    /**
     * The calling user's credentials for this integration, decrypted.
     *
     * The envelope is opened and its identity binding verified inside the SDK,
     * so a connector author cannot skip the check that makes per-user auth mean
     * anything: an envelope sealed for another user throws rather than
     * returning someone else's secrets.
     *
     * @return array<string, string>  the field map the user filled in
     *
     * @throws \Vested\Connect\Sdk\Credential\CredentialUnavailable  none was forwarded
     * @throws \Vested\Connect\Sdk\Credential\CredentialException     not ours to open, or corrupt
     */
    public function credential(): array
    {
        if ($this->credentials === null) {
            throw new CredentialUnavailable(
                'No user credential was supplied for this tool call. Either this connector '
                . 'declares no credential schema, or the platform refused the call before dispatch.'
            );
        }

        return $this->credentials->credential();
    }

    public function callerEmailOrNull(): ?string
    {
        return $this->userEmail === '' ? null : $this->userEmail;
    }

    public function isSystemRun(): bool
    {
        return $this->userId === '';
    }
}
