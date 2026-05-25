<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit;

use Psr\Log\NullLogger;
use Vested\Connect\Sdk\ConnectorApp;
use Vested\Connect\Sdk\Tool\ToolContext;

it('builds an app from the chained builder style', function () {
    $app = ConnectorApp::create()
        ->withLogger(new NullLogger())
        ->withWorkerPoolSize(2)
        ->agent('demo.x')
            ->withInstruction('Be helpful.', position: 0)
            ->withTool(
                key: 'demo.x.echo',
                name: 'Echo',
                description: '',
                inputSchema:  ['type' => 'object', 'properties' => ['s' => ['type' => 'string']]],
                outputSchema: ['type' => 'object'],
                handler: fn (array $a, ToolContext $c) => ['s' => $a['s'] ?? null],
            )
        ->endAgent()
        ->build();

    expect($app->workerPoolSize())->toBe(2);
    expect($app->agents()->declarations())->toHaveCount(1);
    expect($app->tools()->has('demo.x.echo'))->toBeTrue();
    expect(strlen($app->agents()->fingerprint()))->toBe(64);
});

it('builds an app from the scanner style', function () {
    $app = ConnectorApp::create()
        ->scanNamespace(
            'Vested\\Connect\\Sdk\\Tests\\Fixtures\\ExampleAgents',
            __DIR__ . '/../Fixtures/ExampleAgents',
        )
        ->build();

    expect($app->agents()->declarations())->toHaveCount(1);
    expect($app->tools()->has('fixt.products.search'))->toBeTrue();
});
