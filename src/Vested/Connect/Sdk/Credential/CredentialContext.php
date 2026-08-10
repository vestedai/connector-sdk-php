<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Credential;

use Psr\Log\LoggerInterface;

/**
 * Identity context for a credential lifecycle operation.
 *
 * Deliberately carries no agent or tool key — a credential op is not scoped to
 * a tool — and no raw envelope: the SDK opens it and hands the handler
 * plaintext, so connector authors cannot skip the identity check that makes
 * per-user auth mean anything.
 */
final readonly class CredentialContext
{
    public function __construct(
        public string $opId,
        public string $userId,
        public string $userEmail,
        public LoggerInterface $logger,
        /** Calling user's ERP/HR employee number. Empty string when unset. */
        public string $employeeNo = '',
        /** Calling user's ERP identifier. Empty string when unset. */
        public string $erpIdentifier = '',
    ) {}
}
