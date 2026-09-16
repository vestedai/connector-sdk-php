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
        public LoggerInterface $logger,
    ) {}
}
