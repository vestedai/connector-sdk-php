<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Fixtures\ExampleAgents;

use Vested\Connect\Sdk\Attribute\Tool;
use Vested\Connect\Sdk\Tool\ToolContext;
use Vested\Connect\Sdk\Tool\ToolHandler;

#[Tool(
    agentKey: 'fixt.products',
    key: 'fixt.products.search',
    name: 'Search',
    description: 'fixture',
    inputSchema:  ['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'required' => ['q']],
    outputSchema: ['type' => 'object'],
    deadlineMs: 5000,
    maxResultBytes: 65536,
)]
final class SearchTool implements ToolHandler
{
    public function handle(array $args, ToolContext $ctx): array
    {
        return ['echoed' => $args['q']];
    }
}
