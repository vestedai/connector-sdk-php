<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tool;

use InvalidArgumentException;

/**
 * Normalises caller-supplied SQL bind-parameter VALUES into a shape a
 * driver's own parameter binding accepts. Nothing here ever sees, builds, or
 * returns SQL text — it is deliberately a class of static methods, not a
 * base class: both shipped SQL tools already extend PaginatedToolHandler,
 * PHP is single-inheritance, and a second base would be unadoptable.
 *
 * Values are bound by the CALLER's own driver (PDO named binding, etc.) —
 * this class never opens a connection and never touches the statement. A
 * value that is itself SQL text (a quote, a `--` comment, a `DROP TABLE`)
 * comes back byte-identical from {@see normalise()}: nothing here sanitises,
 * escapes, or reinterprets it, because that is the driver's job once bound,
 * not this helper's job before binding.
 *
 * A list value becomes ONE parameter — a single JSON-encoded string — never
 * expanded into `(:p0, :p1, …)` placeholders and never written into the SQL
 * string. The connector's own statement reads it back out INSIDE the
 * database, via `JSON_TABLE` (MySQL 8.0.4+) or `OPENJSON` (SQL Server):
 *
 * ```sql
 * -- mysql
 * WHERE location_code IN (
 *     SELECT v FROM JSON_TABLE(:locations, '$[*]' COLUMNS (v VARCHAR(64) PATH '$')) x
 * )
 * ```
 *
 * A value that cannot be bound this way — most commonly a nested associative
 * array, which is neither a scalar nor a list — is REFUSED with
 * {@see InvalidArgumentException} naming the parameter, rather than silently
 * dropped or stringified.
 */
final class ParameterizedSql
{
    /**
     * ⚠ `{}` vs `[]` after decode: the wire arrives as JSON and is decoded
     * with `json_decode($json, associative: true)`
     * ({@see \Vested\Connect\Sdk\Tool\ToolDispatcher::dispatch()}), which
     * turns BOTH an empty JSON object (`{}`) and an empty JSON array (`[]`)
     * into the identical PHP `[]` — `array_is_list([])` is `true` either
     * way, so the two are genuinely indistinguishable once decoded. This
     * method therefore treats an empty array as an empty LIST (encoded as
     * `"[]"`), never as a value it refuses. That is a deliberate choice, not
     * an oversight: an empty list is a legitimate value (an empty IN-list),
     * and refusing it would break a real case in order to chase one that
     * cannot be told apart from it after decoding.
     *
     * @param  array<string, mixed>  $params  caller-supplied parameter values,
     *         keyed by bind-parameter name
     * @return array<string, string|int|float|bool|null>  ready to hand to the
     *         driver's own bind call, one entry per input key, in the same
     *         order
     */
    public static function normalise(array $params): array
    {
        $out = [];
        foreach ($params as $name => $value) {
            $out[$name] = self::normaliseValue((string) $name, $value);
        }

        return $out;
    }

    private static function normaliseValue(string $name, mixed $value): string|int|float|bool|null
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            // Passed through UNCHANGED — including a value that is itself
            // SQL text. Sanitising or escaping here would be the one place
            // this whole design forbids: the driver's bind is what makes the
            // value inert, and it must see exactly what the caller sent.
            return $value;
        }

        if (is_array($value) && array_is_list($value)) {
            // ONE parameter, not many: the array becomes a single
            // JSON-encoded string, expanded back into rows by the DATABASE
            // (JSON_TABLE / OPENJSON), never by this SDK building SQL text.
            return json_encode($value, JSON_THROW_ON_ERROR);
        }

        throw new InvalidArgumentException(sprintf(
            "parameter '%s' cannot be bound: expected a scalar or a list, got %s",
            $name,
            get_debug_type($value),
        ));
    }

    /**
     * The canonical `properties` fragment for a connector's bind-parameters
     * argument, keyed by $paramsArg (the same name declared as
     * `#[RelationalSource(paramsArg: ...)]`).
     *
     * Two consumers, and both must end up with THIS shape:
     *
     * - A connector using `#[Tool(inputSchema: [...])]` merges the returned
     *   entry straight into its own `properties` array.
     * - A connector using `#[Tool(inputSchemaFile: ...)]` (a hand-written
     *   JSON file, e.g. the live ecommerce connector) keeps its
     *   `properties.<paramsArg>` copy EQUAL to this — a later task asserts
     *   that equality directly, so this method is the single source of
     *   truth and the JSON file is a copy of it, never the other way round.
     *
     * This matters beyond style: the hub validates a tool call's arguments
     * against its input schema BEFORE the connector is ever reached. A
     * hand-written schema with `additionalProperties: false` and no
     * `<paramsArg>` property rejects every parameterized call at the hub,
     * and the failure looks exactly like the connector is broken. Merging
     * (or copying) this fragment is what avoids that.
     *
     * The value schema mirrors {@see normalise()} exactly — a scalar, null,
     * or a list of scalars — so a shape {@see normalise()} would refuse
     * (a nested object, for instance) is already rejected at the schema
     * layer, before this class is ever called.
     *
     * @return array<string, array<string, mixed>>  one entry, keyed by $paramsArg
     */
    public static function inputSchemaFragment(string $paramsArg): array
    {
        $scalarSchema = ['type' => ['string', 'number', 'boolean', 'null']];

        return [
            $paramsArg => [
                'type'        => 'object',
                'description' => 'Named bind parameters for the SQL statement\'s placeholders. '
                    . 'Each value is bound by the driver, never interpolated into the statement '
                    . 'text. A list value is sent as ONE JSON-encoded string parameter, expanded '
                    . 'back into rows inside the database (JSON_TABLE / OPENJSON) — never into '
                    . 'placeholders.',
                'additionalProperties' => [
                    'anyOf' => [
                        $scalarSchema,
                        [
                            'type'  => 'array',
                            'items' => $scalarSchema,
                        ],
                    ],
                ],
            ],
        ];
    }
}
