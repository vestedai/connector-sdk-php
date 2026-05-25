<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Agent
{
    public function __construct(
        public string  $key,
        public string  $name,
        public string  $description = '',
        public string  $status = 'active',
    ) {}
}
