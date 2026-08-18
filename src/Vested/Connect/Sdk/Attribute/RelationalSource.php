<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Attribute;

use Attribute;

/**
 * Marks the class implementing RelationalSchemaProvider as this connector's
 * relational source, and declares how the platform reaches it.
 *
 * Declaring one is what makes the connector's database visible to schema
 * extraction: a connector that registers no annotated provider registers no
 * relational_source, so the core never extracts its schema and — in a later
 * slice — never governs its SQL. Same "declare nothing, stay untouched"
 * contract as #[CredentialSchema].
 *
 * Apply once, on the class you hand to ConnectorApp::withRelationalSchemaProvider().
 * All four values are specific to YOUR connector — the tool keys live in your
 * namespace and the SQL argument name is whatever your tool's input schema
 * calls it — which is why they are declared on your class rather than
 * configured on an SDK-owned one.
 *
 * There is deliberately no fingerprint here. The catalog fingerprint is read
 * LIVE from the provider each time Register is built: a value captured when the
 * provider was registered would be stale the moment the source catalog changed,
 * and a stale fingerprint tells the core "nothing changed, do not re-extract" —
 * precisely the silently-wrong-schema failure this layer exists to prevent.
 *
 * ```php
 * #[RelationalSource(
 *     engine: 'mysql',
 *     describeTool: 'magento.describe_schema',
 *     queryTool: 'magento.query_sql',
 *     sqlArg: 'sql',
 * )]
 * final class MagentoSchemaProvider implements RelationalSchemaProvider { … }
 *
 * $app->withRelationalSchemaProvider(new MagentoSchemaProvider($pdo));
 * ```
 *
 * A source spanning more than one scope (multiple MySQL databases, multiple
 * BC companies — see RelationalSchemaProvider::scopes()) MUST name a
 * defaultScope: it is the only party that knows which scope an UNQUALIFIED
 * table name should resolve in, and ConnectorApp::build() throws
 * InvalidArgumentException rather than let that ambiguity reach a query at
 * runtime. A single-scope (or scope-less) source may omit it.
 *
 * ```php
 * #[RelationalSource(
 *     engine: 'mysql',
 *     describeTool: 'erp.describe_schema',
 *     queryTool: 'erp.query_sql',
 *     sqlArg: 'sql',
 *     defaultScope: 'production',
 * )]
 * final class ErpSchemaProvider implements RelationalSchemaProvider {
 *     public function scopes(): array { return ['production', 'erp_middleware_production']; }
 *     // …
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RelationalSource
{
    /**
     * @param  string  $engine        the database engine behind this connector, e.g. "sqlserver"
     *                                or "mysql". It selects the core's dialect handling; the core
     *                                owns the supported set and names it when it rejects one.
     * @param  string  $describeTool  key of the rowset tool that returns this source's canonical schema
     * @param  string  $queryTool     key of the free-form SQL tool the core's query gate governs
     * @param  string  $sqlArg        which argument of $queryTool carries the SQL text. Per-connector
     *                                and never assumed: it must match the tool's input schema
     *                                exactly, including case — naming the wrong key reads null
     *                                downstream and silently gates nothing.
     * @param  string  $defaultScope  which of RelationalSchemaProvider::scopes() an UNQUALIFIED
     *                                table name resolves in. Required when scopes() returns more
     *                                than one entry; ConnectorApp::build() throws otherwise. Leave
     *                                blank for a source with zero or one scope. A qualified
     *                                `scope.table` is never re-pointed at this default, and
     *                                cross-scope joins are unaffected by it.
     *
     *                                With exactly ONE scope this value is IGNORED and the sole
     *                                scope is declared instead — it is a compile-time constant
     *                                while scopes() is runtime data, so a connector naming its
     *                                production database here still declares truthfully in an
     *                                environment whose database is named something else.
     */
    public function __construct(
        public string $engine,
        public string $describeTool,
        public string $queryTool,
        public string $sqlArg,
        public string $defaultScope = '',
        /**
         * Which argument of $queryTool carries bind parameters, as an object of
         * name => value. Empty = this source takes none.
         *
         * ⚠ Values behind this argument are BOUND by the connector, never
         * interpolated into the SQL.
         */
        public string $paramsArg = '',
    ) {}
}
