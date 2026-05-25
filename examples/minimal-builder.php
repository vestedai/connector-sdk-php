<?php

declare(strict_types=1);

// Minimal builder-style ConnectorApp — ~30 lines, one agent, one tool.
//
// Run with:
//   VESTED_CONNECTOR_TOKEN=eyJ... vendor/bin/vested-connect worker --bootstrap=examples/minimal-builder.php

require_once __DIR__ . '/../vendor/autoload.php';

use Vested\Connect\Sdk\ConnectorApp;
use Vested\Connect\Sdk\Tool\ToolContext;

return ConnectorApp::create()
    ->withWorkerPoolSize(2)
    ->agent('example.echo')
        ->name('Echo agent')
        ->description('A trivial agent that echoes back its input.')
        ->withModel('openai', 'gpt-4o', ['temperature' => 0.0])
        ->withInstruction(
            'You are an echo agent. When the user asks for an echo, call the echo tool.',
            type: 'system',
            position: 0,
        )
        ->withTool(
            key: 'example.echo.bounce',
            name: 'Bounce',
            description: 'Returns the input string verbatim.',
            inputSchema: [
                'type' => 'object',
                'properties' => ['s' => ['type' => 'string']],
                'required' => ['s'],
            ],
            outputSchema: [
                'type' => 'object',
                'properties' => ['echoed' => ['type' => 'string']],
                'required' => ['echoed'],
            ],
            handler: function (array $args, ToolContext $ctx): array {
                $ctx->logger->info('bouncing', ['s' => $args['s']]);
                return ['echoed' => $args['s']];
            },
        )
    ->endAgent()
    ->build();
