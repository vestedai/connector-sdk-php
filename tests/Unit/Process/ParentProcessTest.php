<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Process;

use Psr\Log\NullLogger;
use Vested\Connect\Sdk\ConnectorApp;
use Vested\Connect\Sdk\Exception\TokenException;
use Vested\Connect\Sdk\Process\ParentProcess;
use Vested\Connect\Sdk\Tool\ToolContext;

it('refuses to start with an empty token', function () {
    $app = ConnectorApp::create()
        ->agent('x.y')
            ->withTool(
                key: 'x.y.t', name: 'T', description: '',
                inputSchema: ['type' => 'object'], outputSchema: ['type' => 'object'],
                handler: fn (array $a, ToolContext $c) => [],
            )
        ->endAgent()
        ->build();

    expect(fn () => new ParentProcess(
        app: $app,
        token: '',
        hubAddr: 'localhost:9092',
        insecure: true,
        logger: new NullLogger(),
    ))->toThrow(TokenException::class);
});

it('exposes the configured worker pool size', function () {
    $app = ConnectorApp::create()->withWorkerPoolSize(10)
        ->agent('x.y')
            ->withTool(
                key: 'x.y.t', name: 'T', description: '',
                inputSchema: ['type' => 'object'], outputSchema: ['type' => 'object'],
                handler: fn (array $a, ToolContext $c) => [],
            )
        ->endAgent()
        ->build();

    $p = new ParentProcess(
        app: $app, token: 'eyJ.test.sig', hubAddr: 'localhost:9092',
        insecure: true, logger: new NullLogger(),
    );
    expect($p->requestedWorkerPoolSize())->toBe(10);
});
