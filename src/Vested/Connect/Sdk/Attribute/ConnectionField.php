<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Attribute;

use Attribute;

/** One field of a `fields` connection profile's form. Stored encrypted by the platform. */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class ConnectionField
{
    public const TYPES = ['text', 'password', 'url', 'select', 'textarea'];

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
