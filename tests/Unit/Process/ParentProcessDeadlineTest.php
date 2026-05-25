<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Process;

use Psr\Log\NullLogger;
use Vested\Connect\Sdk\ConnectorApp;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectorMsg;
use Vested\Connect\Sdk\Process\Ipc;
use Vested\Connect\Sdk\Process\ParentProcess;
use Vested\Connect\Sdk\Process\WorkerPool;
use Vested\Connect\Sdk\Tool\ToolContext;

it('synthesizes deadline_exceeded when an invocation exceeds its deadline', function () {
    $app = ConnectorApp::create()
        ->withWorkerPoolSize(1)
        ->agent('test.x')
            ->withTool(
                key: 'test.x.t', name: 'T', description: '',
                inputSchema:  ['type' => 'object'],
                outputSchema: ['type' => 'object'],
                handler: fn (array $a, ToolContext $c) => [],
            )
        ->endAgent()
        ->build();

    $proc = new ParentProcess(
        app: $app, token: 'eyJ.t.s', hubAddr: 'localhost:9092',
        insecure: true, logger: new NullLogger(),
    );

    // Long-lived worker child so the deadline trigger has something to SIGTERM.
    $pool = new WorkerPool(
        size: 1,
        spawn: function ($s): void {
            // @phpstan-ignore-next-line while.alwaysTrue (intentional infinite loop in child process)
            while (true) {
                @fread($s, 1024);
                usleep(50_000);
            }
        },
        logger: new NullLogger(),
    );
    $pool->start();

    // Real Unix-socket pair stands in for the parent <-> reader pipe.
    // enforceDeadlines now writes the deadline-exceeded ConnectorMsg
    // through Ipc::writeMessage; we read it back from the other end.
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    assert($pair !== false);
    [$parentEnd, $remoteEnd] = $pair;

    // Simulate an in-flight invocation whose deadline has already passed.
    $sock = $pool->acquire();
    $inFlight = [
        'inv-1' => [
            'socket'         => $sock,
            'deadlineUnixMs' => (int) (microtime(true) * 1000) - 1, // already past
        ],
    ];

    $before = microtime(true);
    $proc->enforceDeadlines($pool, $inFlight, $parentEnd);

    // inFlight entry must be removed after enforcement.
    expect($inFlight)->toBeEmpty();

    // Exactly one ConnectorMsg should have been written to the pipe.
    $received = Ipc::readMessage($remoteEnd, ConnectorMsg::class);
    expect($received)->toBeInstanceOf(ConnectorMsg::class);
    assert($received !== null);
    $tcr = $received->getToolCallResponse();
    assert($tcr !== null);
    expect($tcr->getInvocationId())->toBe('inv-1');
    expect($tcr->getError())->toBe('deadline_exceeded');

    // Verify enforceDeadlines no longer busy-waits: clock should move < 100 ms.
    $elapsed = microtime(true) - $before;
    expect($elapsed)->toBeLessThan(0.1);

    @fclose($parentEnd);
    @fclose($remoteEnd);
    $pool->shutdown(timeoutSeconds: 2);
})->skip(! function_exists('pcntl_fork'), 'requires pcntl');

it('processPendingKills is a no-op when the map is empty', function () {
    $app = ConnectorApp::create()
        ->withWorkerPoolSize(1)
        ->agent('test.x')
            ->withTool(
                key: 'test.x.t', name: 'T', description: '',
                inputSchema:  ['type' => 'object'],
                outputSchema: ['type' => 'object'],
                handler: fn (array $a, ToolContext $c) => [],
            )
        ->endAgent()
        ->build();

    $proc = new ParentProcess(
        app: $app, token: 'eyJ.t.s', hubAddr: 'localhost:9092',
        insecure: true, logger: new NullLogger(),
    );

    // processPendingKills with an empty map must not throw or block.
    $proc->processPendingKills();
    expect(true)->toBeTrue();
});
