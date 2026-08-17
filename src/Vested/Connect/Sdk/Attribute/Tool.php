<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Tool
{
    /**
     * @param  string|list<string>  $agentKey  the agent, or agents, this tool
     *   is bound to. A plain string is the historical form and keeps working.
     *
     *   A LIST binds one declaration to several agents, so behaviour shared
     *   across agents is written once instead of duplicated per namespace. The
     *   list is AUTHORITATIVE: the tool key's own namespace confers nothing, so
     *   a tool may live in one namespace and be callable only from another.
     *
     *   `'*'` (in either form) means every agent this connector declares,
     *   resolved when the declarations are scanned, so an agent added later
     *   picks the tool up. It cannot be combined with explicit keys.
     *
     *   Every named agent must be one this connector declares — the scanner
     *   refuses an unknown key rather than binding the tool to nothing at all,
     *   silently.
     *
     * @param array<string, mixed>|null $inputSchema
     * @param array<string, mixed>|null $outputSchema
     */
    public function __construct(
        public string|array $agentKey,
        public string $key,
        public string $name,
        public string $description = '',
        public string $inputSchemaFile = '',
        public string $outputSchemaFile = '',
        public ?array $inputSchema = null,
        public ?array $outputSchema = null,
        public int    $deadlineMs = 30000,
        public int    $maxResultBytes = 1048576,
        public string $sensitivity = '',
    ) {}
}
