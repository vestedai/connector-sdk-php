<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Connection;

use Psr\Log\LoggerInterface;

final readonly class ConnectionOpContext
{
    public function __construct(
        public string $opId,
        public string $organizationId,
        public string $profile,
        /** "0" when validating a fields credential the platform has not stored yet. */
        public string $tenantConnectionId,
        /**
         * How long the hub is prepared to wait for this op, from
         * ConnectionOpRequest.deadline_ms — the same fact ToolContext exposes
         * to a tool. A handler that calls a third-party API should bound its
         * own client with it; without it the handler keeps waiting after the
         * hub has already given up, and on the inline dispatch path that holds
         * the connector's only stream (see Swoole\Daemon).
         */
        public int $deadlineMs,
        public LoggerInterface $logger,
    ) {}
}
