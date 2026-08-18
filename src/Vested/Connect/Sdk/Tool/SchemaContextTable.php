<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tool;

/**
 * One table (or view) the core's SQL gate resolved a governed `run_sql` call
 * against.
 *
 * `$logicalName` is the platform's label for the entity, not a queryable
 * object name — on Business Central, for instance, the real object carries a
 * company prefix and an extension suffix. Key any permission check on
 * `$physical`, the canonical name(s) this entity resolved to.
 */
final readonly class SchemaContextTable
{
    /**
     * @param list<string> $physical  the physical name(s) this entity
     *        resolved to, canonical as stored in the snapshot — not as the
     *        model spelled them, which resolution matches
     *        case-insensitively. NOT necessarily every physical name the
     *        statement touches: in `"observe"`, an entity the core refused
     *        never gets a `SchemaContextTable` at all, even though the
     *        statement still reads it — see `SchemaContext`'s own docblock.
     */
    public function __construct(
        public string $logicalName,
        public string $scope,
        public string $kind,
        public array $physical,
    ) {
    }
}
