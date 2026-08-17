<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Schema;

/**
 * Turns one engine's catalog into the canonical model. Engine specifics live
 * here and nowhere above: the core sees entities with variant sets whether
 * the source is SQL Server table-extensions or Magento's EAV value tables.
 *
 * Present in this SDK so the contract is identical across languages, but no
 * PHP connector implements it yet — ecommerce, marketing and oto expose only
 * curated tools. A connector that registers no provider declares no
 * relational_source and is untouched by schema extraction, exactly as a
 * connector declaring no credential_schema is untouched by the credential
 * gate.
 *
 * The first implementation will be MySQL for Magento, where a logical entity
 * spans an EAV value-table set joined on entity_id — the same variant-set
 * shape SQL Server's table extensions produce.
 *
 * catalogFingerprint() must detect column-level change, not just table-level
 * change: hashing table names alone misses a field being added to an
 * existing table, which is the normal shape of a backward-compatible
 * extension deploy, and would leave the core believing the schema is
 * unchanged. The .NET SqlServerProvider hashes sorted
 * "{schema}.{table}|{columnCount}" entries for this reason; any PHP
 * implementation must do the equivalent.
 */
interface RelationalSchemaProvider
{
    /**
     * @return string[] scopes (databases) this source exposes
     *
     * Called SYNCHRONOUSLY during worker bootstrap — from
     * ConnectorApp::build(), before the worker ever dials the hub — to
     * validate against #[RelationalSource]'s defaultScope. There is
     * deliberately no async variant and no timeout around this call, unlike
     * the .NET SDK's IRelationalSchemaProvider.ScopesAsync(): PHP has no
     * async model to fall back on here, so whatever this method does runs
     * INLINE and BLOCKING on the calling thread.
     *
     * It MUST therefore be cheap and I/O-free — return a declared/constant
     * list (or one already held in memory), never a live catalog query. A
     * live database round trip here blocks worker startup on that query,
     * and a slow or unreachable database delays or fails the boot entirely,
     * which is a far worse failure than the stale-schema risk
     * catalogFingerprint()'s live read is built to avoid. If you need a
     * LIVE, per-deployment scope list (e.g. enumerating BC companies from
     * the database), do that enumeration in describe() or elsewhere in your
     * own tool/warm-up path — never here.
     */
    public function scopes(): array;

    public function describe(string $scopeKey): CanonicalSchema;

    /**
     * A cheap hash of the source catalog, reported on Register. The core
     * re-extracts only when this changes, so a connector that knows its
     * database is unchanged costs the platform nothing.
     */
    public function catalogFingerprint(): string;
}
