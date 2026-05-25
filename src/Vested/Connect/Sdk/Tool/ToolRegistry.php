<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tool;

use Closure;
use Vested\Connect\Sdk\Agent\AgentBuilder;
use Vested\Connect\Sdk\Exception\ConfigException;

/**
 * Immutable map of tool_key → handler (closure OR ToolHandler instance).
 * Built once at boot from the merged AgentBuilders.
 */
final class ToolRegistry
{
    /**
     * @param  array<string, Closure|ToolHandler>  $handlers
     */
    public function __construct(private readonly array $handlers) {}

    /** @param iterable<AgentBuilder> $agents */
    public static function fromAgents(iterable $agents): self
    {
        $handlers = [];
        foreach ($agents as $a) {
            foreach ($a->allHandlers() as $key => $h) {
                if (isset($handlers[$key])) {
                    throw new ConfigException("duplicate tool_key '{$key}' across agents");
                }
                $handlers[$key] = $h;
            }
        }
        return new self($handlers);
    }

    public function has(string $toolKey): bool { return isset($this->handlers[$toolKey]); }

    public function resolve(string $toolKey): Closure|ToolHandler
    {
        if (! isset($this->handlers[$toolKey])) {
            throw new ConfigException("no handler registered for tool_key '{$toolKey}'");
        }
        return $this->handlers[$toolKey];
    }

    /** @return list<string> */
    public function keys(): array { return array_keys($this->handlers); }
}
