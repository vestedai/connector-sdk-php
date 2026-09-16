<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Fixtures\ConnectionAgents;

use Vested\Connect\Sdk\Attribute\Tool;
use Vested\Connect\Sdk\Tool\ToolContext;
use Vested\Connect\Sdk\Tool\ToolHandler;

#[Tool(
    agentKey: 'mkt.ga4',
    key: 'mkt.ga4.report',
    name: 'Report',
    description: 'A report',
    inputSchema: ['type' => 'object'],
    outputSchema: ['type' => 'object'],
)]
final class Ga4ReportTool implements ToolHandler
{
    public function handle(array $args, ToolContext $ctx): array
    {
        return ['property' => $ctx->connections()->one('google')->resource()['id'] ?? null];
    }
}
