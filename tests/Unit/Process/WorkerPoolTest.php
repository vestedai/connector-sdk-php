<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Process;

use Psr\Log\NullLogger;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ToolCallRequest;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ToolCallResponse;
use Vested\Connect\Sdk\Process\Ipc;
use Vested\Connect\Sdk\Process\WorkerPool;
use Vested\Connect\Sdk\Process\WorkerProcess;
use Vested\Connect\Sdk\Tool\ToolDispatcher;
use Vested\Connect\Sdk\Tool\ToolRegistry;

it('spawns N workers and dispatches a tool call through one of them', function () {
    $registry = new ToolRegistry(['x.y.echo' => fn (array $a, $c) => ['echoed' => $a['s']]]);
    $dispatcher = new ToolDispatcher($registry, toolMeta: [
        'x.y.echo' => [
            'input_schema'  => ['type' => 'object', 'properties' => ['s' => ['type' => 'string']], 'required' => ['s']],
            'output_schema' => ['type' => 'object'],
        ],
    ], logger: new NullLogger());

    $pool = new WorkerPool(
        size: 2,
        spawn: function ($socket) use ($dispatcher): void {
            (new WorkerProcess($socket, $dispatcher, new NullLogger()))->run();
        },
        logger: new NullLogger(),
    );
    $pool->start();
    expect($pool->idleCount())->toBe(2);

    $sock = $pool->acquire();
    expect($sock)->not->toBeNull();
    expect($pool->idleCount())->toBe(1);

    Ipc::writeMessage($sock, new ToolCallRequest([
        'invocation_id' => 'inv-1', 'tool_key' => 'x.y.echo', 'agent_key' => 'x.y',
        'args_json' => '{"s":"hi"}', 'organization_id' => '7',
        'user_id' => '', 'user_email' => '', 'conversation_id' => 'C', 'deadline_ms' => 1000,
    ]));
    $resp = Ipc::readMessage($sock, ToolCallResponse::class);
    expect($resp->getInvocationId())->toBe('inv-1');
    expect(json_decode($resp->getResultJson(), true))->toBe(['echoed' => 'hi']);

    $pool->release($sock);
    expect($pool->idleCount())->toBe(2);

    $pool->shutdown(timeoutSeconds: 2);
})->skip(extension_loaded('pcntl') === false, 'requires ext-pcntl');
