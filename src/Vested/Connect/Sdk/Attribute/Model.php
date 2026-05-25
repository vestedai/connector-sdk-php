<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Model
{
    /** @param array<string, mixed> $config */
    public function __construct(
        public string $provider,
        public string $name,
        public array  $config = [],
    ) {}
}
