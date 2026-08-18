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
 */
final readonly class SchemaContext
{
    /**
     * @param list<SchemaContextTable> $tables  the tables/views the gate
     *        resolved. Can be empty on a PRESENT context — see the class
     *        docblock above for why that is not the same claim as the
     *        context itself being null.
     * @param bool $hasStar  the statement selects `*` somewhere. Carried
     *        because a connector's own rule may be stricter than the core's
     *        about unbounded reads.
     * @param string $gateMode  `"enforce"` | `"observe"`. Lets a handler
     *        tell "the core refused this and is letting it through anyway"
     *        (observe) from "the core allowed it" (enforce) — the core not
     *        enforcing does not mean the connector should not.
     */
    public function __construct(
        public array $tables,
        public bool $hasStar,
        public string $gateMode,
    ) {
    }
}
