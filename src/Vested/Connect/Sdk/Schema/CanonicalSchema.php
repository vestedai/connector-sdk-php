<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Schema;

/**
 * The canonical model of one scope's schema, as plain rows.
 *
 * Rows are plain arrays rather than typed objects because they go straight
 * out as a rowset — the core reads them with CanonicalEntity::fromArray()
 * on the other side of the wire. The row shape is therefore the contract,
 * and the key names are deliberately snake_case (matching the .NET SDK's
 * serialized wire format, not this SDK's usual PHP conventions):
 *
 *   - entities[]: logical_name, scope_key, kind, comment, join_key,
 *     variants[], columns[]
 *   - relations[]: from_entity, from_columns, to_entity, to_columns, kind
 *
 * `variants[]` is the point: one logical entity can be assembled from
 * several physical tables (SQL Server table-extensions, or an EAV
 * value-table set joined on entity_id) joined on `join_key`.
 */
final class CanonicalSchema
{
    /**
     * @param  array<int, array<string, mixed>>  $entities
     * @param  array<int, array<string, mixed>>  $relations
     */
    public function __construct(
        public readonly array $entities,
        public readonly array $relations,
    ) {}
}
