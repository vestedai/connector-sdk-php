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
