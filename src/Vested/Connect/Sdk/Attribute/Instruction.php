<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Instruction
{
    public function __construct(
        public string $type,
        public int    $position,
        public string $body,
        public string $format = 'markdown',
    ) {}
}
