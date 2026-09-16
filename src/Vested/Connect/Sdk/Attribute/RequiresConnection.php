<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Attribute;

use Attribute;

/**
 * An agent needs a tenant connection of `profile` bound before it can run.
 * `many` lets a tenant bind several (one MoEngage workspace per market), each
 * with a label described by `labelHint`.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class RequiresConnection
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $profile,
        public array $scopes = [],
        public string $cardinality = 'one',
        public string $labelHint = '',
    ) {}
}
