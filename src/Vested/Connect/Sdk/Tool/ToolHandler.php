<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tool;

/**
 * Implemented by class-based tool handlers discovered via the
 * #[Tool] attribute. The closure-based path (AgentBuilder::withTool)
 * wraps a Closure that matches this same signature.
 */
interface ToolHandler
{
    /**
     * @param  array<string, mixed>  $args   Decoded args_json; already validated against input_schema.
     * @param  ToolContext           $ctx    Invocation metadata.
     * @return array<string, mixed>          Tool result; will be validated against output_schema.
     */
    public function handle(array $args, ToolContext $ctx): array;
}
