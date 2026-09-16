<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Connection;

final readonly class ConnectionResource
{
    /** @param array<string, scalar|null> $meta non-secret facts, e.g. ['currency' => 'SAR', 'time_zone' => 'Asia/Riyadh'] */
    public function __construct(
        public string $id,
        public string $label,
        public array $meta = [],
    ) {}
}
