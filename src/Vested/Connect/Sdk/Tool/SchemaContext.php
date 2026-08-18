<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tool;

/**
 * What the core's SQL gate resolved for a governed `run_sql` call, so a
 * connector can apply its OWN permission layer on top of the core's
 * decision.
 *
 * Advisory and one-way: the core has already decided. A handler is free to
 * refuse a call its own rules reject; nothing it does here reaches back to
 * the core.
 *
 * Lives on `ToolContext::$schemaContext`, which is nullable — see that
 * property's docblock for why a null context and a present-but-empty one
 * are different claims.
 *
 * ⚠ IN `"observe"`, `$tables` IS NOT THE COMPLETE SET OF OBJECTS THIS
 * STATEMENT TOUCHES. A table the core's per-entity check refused is excluded
 * from this list, but in `observe` the call proceeds and reads it anyway —
 * so a statement joining a denied table alongside granted ones arrives with
 * `$tables` missing exactly the table the core flagged. Treat this list as
 * "every object the core is willing to vouch for", never as "every object
 * the statement reads".
 */
final readonly class SchemaContext
{
    /**
     * @param list<SchemaContextTable> $tables  the tables/views the gate
     *        resolved. Can be empty on a PRESENT context — see the class
     *        docblock above for why that is not the same claim as the
     *        context itself being null. See also the `observe` warning
     *        above: even non-empty, this is not guaranteed complete.
     * @param bool $hasStar  the statement selects `*` somewhere. Carried
     *        because a connector's own rule may be stricter than the core's
     *        about unbounded reads.
     * @param string $gateMode  `"enforce"` | `"observe"` — which mode the
     *        CONNECTOR's gate is configured in. ⚠ It does NOT distinguish
     *        "the core refused this and is letting it through anyway" from
     *        "the core allowed it": `$gateMode` reads exactly `"observe"` in
     *        BOTH cases — a genuine allow and a refusal the call is
     *        proceeding through look identical on this field. Nothing on
     *        this class says which one happened.
     */
    public function __construct(
        public array $tables,
        public bool $hasStar,
        public string $gateMode,
    ) {
    }
}
