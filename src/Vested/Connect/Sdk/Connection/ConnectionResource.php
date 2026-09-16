<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Connection;

final readonly class ConnectionResource
{
    /**
     * @param array<string, scalar|\Stringable|null> $meta non-secret facts, e.g. ['currency' => 'SAR', 'time_zone' => 'Asia/Riyadh']
     *
     * Stringable is named here because ConnectionOpDispatcher::toValue()
     * accepts it and a test pins that; a narrower annotation made the
     * supported case look like a type error at the call site.
     */
    public function __construct(
        public string $id,
        public string $label,
        public array $meta = [],
    ) {}
}
