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

it('reconcilePoolSize honours the bootstrap size when the hub cap is permissive', function () {
    $app = ConnectorApp::create()->withWorkerPoolSize(4)
        ->agent('x.y')
            ->withTool(
                key: 'x.y.t', name: 'T', description: '',
                inputSchema: ['type' => 'object'], outputSchema: ['type' => 'object'],
                handler: fn (array $a, ToolContext $c) => [],
            )
        ->endAgent()
        ->build();

    $p = new ParentProcess(
        app: $app, token: 'eyJ.t.s', hubAddr: 'localhost:9092',
        insecure: true, logger: new NullLogger(),
    );

    // Hub permits more than we asked for → keep our requested size.
    expect($p->reconcilePoolSize(requested: 4, hubMaxConcurrent: 16))->toBe(4);

    // Hub permits exactly what we asked for.
    expect($p->reconcilePoolSize(requested: 4, hubMaxConcurrent: 4))->toBe(4);

    // Hub cap is 0 or below → treat as "no cap" and respect bootstrap.
    expect($p->reconcilePoolSize(requested: 4, hubMaxConcurrent: 0))->toBe(4);
    expect($p->reconcilePoolSize(requested: 4, hubMaxConcurrent: -1))->toBe(4);
});

it('reconcilePoolSize downsizes when the hub cap is below the requested size', function () {
    $app = ConnectorApp::create()->withWorkerPoolSize(10)
        ->agent('x.y')
            ->withTool(
                key: 'x.y.t', name: 'T', description: '',
                inputSchema: ['type' => 'object'], outputSchema: ['type' => 'object'],
                handler: fn (array $a, ToolContext $c) => [],
            )
        ->endAgent()
        ->build();

    $logger = new class extends \Psr\Log\AbstractLogger {
        /** @var array<int, array{level: mixed, message: string, context: array<string, mixed>}> */
        public array $entries = [];
        public function log($level, string|\Stringable $message, array $context = []): void
        {
            $this->entries[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
        }
    };

    $p = new ParentProcess(
        app: $app, token: 'eyJ.t.s', hubAddr: 'localhost:9092',
        insecure: true, logger: $logger,
    );

    expect($p->reconcilePoolSize(requested: 10, hubMaxConcurrent: 3))->toBe(3);

    // A warning must have been logged with both values.
    $warnings = array_filter($logger->entries, fn (array $e) => $e['level'] === \Psr\Log\LogLevel::WARNING);
    expect($warnings)->not->toBeEmpty();
    $first = array_values($warnings)[0];
    expect($first['message'])->toContain('downsizing worker pool');
    expect($first['context']['requested'])->toBe(10);
    expect($first['context']['hub_cap'])->toBe(3);
    expect($first['context']['effective'])->toBe(3);
});
