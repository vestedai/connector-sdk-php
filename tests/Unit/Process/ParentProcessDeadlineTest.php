<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Process;

use Psr\Log\NullLogger;
use Vested\Connect\Sdk\ConnectorApp;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectorMsg;
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
            while (true) {
                @fread($s, 1024);
                usleep(50_000);
            }
        },
        logger: new NullLogger(),
    );
    $pool->start();

    // Fake stream that captures writes.
    $captured = [];
    $fakeStream = new class($captured) {
        /** @param array<int, ConnectorMsg> $captured */
        public function __construct(public array &$captured) {}
        public function write(ConnectorMsg $msg): void
        {
            $this->captured[] = $msg;
        }
    };

    // Simulate an in-flight invocation whose deadline has already passed.
    $sock = $pool->acquire();
    $inFlight = [
        'inv-1' => [
            'socket'         => $sock,
            'deadlineUnixMs' => (int) (microtime(true) * 1000) - 1, // already past
        ],
    ];

    $proc->enforceDeadlines($pool, $inFlight, $fakeStream);

    // inFlight entry must be removed after enforcement.
    expect($inFlight)->toBeEmpty();

    // Exactly one message must have been written to the stream.
    expect($fakeStream->captured)->toHaveCount(1);

    /** @var ConnectorMsg $sent */
    $sent = $fakeStream->captured[0];
    $tcr = $sent->getToolCallResponse();
    expect($tcr->getInvocationId())->toBe('inv-1');
    expect($tcr->getError())->toBe('deadline_exceeded');

    $pool->shutdown(timeoutSeconds: 2);
})->skip(! function_exists('pcntl_fork'), 'requires pcntl');
