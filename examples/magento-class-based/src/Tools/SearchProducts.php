<?php

declare(strict_types=1);

namespace Magento\Connector\Tools;

use Vested\Connect\Sdk\Attribute\Tool;
use Vested\Connect\Sdk\Tool\ToolContext;
use Vested\Connect\Sdk\Tool\ToolHandler;

#[Tool(
    agentKey:         'magento.products',
    key:              'magento.products.search',
    name:             'Search products',
    description:      'Returns up to 20 products matching the query.',
    inputSchemaFile:  __DIR__ . '/schemas/search.input.json',
    outputSchemaFile: __DIR__ . '/schemas/search.output.json',
    deadlineMs:       5000,
    maxResultBytes:   65536,
)]
final class SearchProducts implements ToolHandler
{
    public function handle(array $args, ToolContext $ctx): array
    {
        $ctx->logger->info('searching', ['q' => $args['q'], 'caller' => $ctx->userEmail]);
        // In a real Magento connector this would call the Magento REST API
        // as the caller (using ctx->userEmail to scope the request).
        return [
            'items' => [
                ['sku' => 'EXAMPLE-1', 'name' => 'Example product', 'price' => 9.99],
            ],
        ];
    }
}
