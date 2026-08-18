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
 * `$physical`, the canonical name(s) actually referenced.
 */
final readonly class SchemaContextTable
{
    /**
     * @param list<string> $physical  the physical name(s) this statement
     *        actually referenced, canonical as stored in the snapshot — not
     *        as the model spelled them, which resolution matches
     *        case-insensitively
     */
    public function __construct(
        public string $logicalName,
        public string $scope,
        public string $kind,
        public array $physical,
    ) {
    }
}
