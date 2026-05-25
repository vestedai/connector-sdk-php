<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Integration;

use Psr\Log\NullLogger;
use Vested\Connect\Sdk\ConnectorApp;
use Vested\Connect\Sdk\Process\ParentProcess;
use Vested\Connect\Sdk\Tool\ToolContext;

it('connects to the live hub, registers an agent, accepts RegisterAck', function () {
    $token = getenv('VESTED_CONNECTOR_TOKEN');
    expect($token)->not->toBeEmpty('set VESTED_CONNECTOR_TOKEN to a valid connector JWT');

    $app = ConnectorApp::create()
        ->withLogger(new NullLogger())
        ->withWorkerPoolSize(2)
        ->agent('test_smoke.products')
            ->withModel('openai', 'gpt-4o')
            ->withInstruction('You are a smoke-test agent.', position: 0)
            ->withTool(
                key: 'test_smoke.products.search', name: 'Search', description: '',
                inputSchema:  ['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'required' => ['q']],
                outputSchema: ['type' => 'object', 'properties' => ['items' => ['type' => 'array']]],
                handler: fn (array $a, ToolContext $c) => ['items' => [['sku' => 'S-1']]],
            )
        ->endAgent()
        ->build();

    $process = new ParentProcess(
        app: $app, token: $token,
        hubAddr: (string) (getenv('VESTED_CONNECTOR_HUB') ?: ''),
        insecure: false, logger: new NullLogger(),
    );

    // SIGALRM after 8s → triggers SIGTERM via the inner alarm handler → shouldExit=true
    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, fn () => posix_kill(posix_getpid(), SIGTERM));
    pcntl_alarm(8);

    try {
        $process->run();
        $this->assertTrue(true, 'run() returned without throwing');
    } catch (\Throwable $e) {
        $this->fail('run() threw: ' . $e->getMessage());
    }
})->skip(getenv('INTEGRATION') !== '1', 'set INTEGRATION=1 + VESTED_CONNECTOR_TOKEN to run');
