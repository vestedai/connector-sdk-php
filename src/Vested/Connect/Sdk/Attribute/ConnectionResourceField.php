<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Attribute;

use Attribute;

/**
 * A manually entered, NON-SECRET resource field (e.g. a property id) for a
 * profile whose resources cannot be listed. The platform stores these in plain
 * text, so a `password` type is refused.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class ConnectionResourceField
{
    /** @param list<string> $options */
    public function __construct(
        public string $key,
        public string $label = '',
        public string $type = 'text',
        public bool $required = true,
        public string $placeholder = '',
        public array $options = [],
    ) {}
}
