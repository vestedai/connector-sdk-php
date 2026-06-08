<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tool;

use DateTimeImmutable;
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
    ) {}

    public function callerEmailOrNull(): ?string
    {
        return $this->userEmail === '' ? null : $this->userEmail;
    }

    public function isSystemRun(): bool
    {
        return $this->userId === '';
    }
}
