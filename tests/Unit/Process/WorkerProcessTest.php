<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Process;

use Psr\Log\NullLogger;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ToolCallRequest;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ToolCallResponse;
use Vested\Connect\Sdk\Process\Ipc;
use Vested\Connect\Sdk\Process\WorkerProcess;
use Vested\Connect\Sdk\Tool\ToolDispatcher;
use Vested\Connect\Sdk\Tool\ToolRegistry;

it('processes one request and writes one response via a paired socket', function () {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    assert($pair !== false);
    [$a, $b] = $pair;

    $registry = new ToolRegistry(['x.y.echo' => fn (array $args, $c) => ['echoed' => $args['s']]]);
    $dispatcher = new ToolDispatcher($registry, toolMeta: [
        'x.y.echo' => [
            'input_schema'  => ['type' => 'object', 'properties' => ['s' => ['type' => 'string']], 'required' => ['s']],
            'output_schema' => ['type' => 'object'],
        ],
    ], logger: new NullLogger());

    Ipc::writeMessage($a, new ToolCallRequest([
        'invocation_id' => 'inv-1', 'tool_key' => 'x.y.echo', 'agent_key' => 'x.y',
        'args_json' => '{"s":"hi"}', 'organization_id' => '7',
        'user_id' => '', 'user_email' => '', 'conversation_id' => 'C', 'deadline_ms' => 1000,
    ]));

    $worker = new WorkerProcess($b, $dispatcher, new NullLogger());
    $worker->processOne();   // single-step API for testability

    $resp = Ipc::readMessage($a, ToolCallResponse::class);
    assert($resp !== null);
    expect($resp->getInvocationId())->toBe('inv-1');
    expect(json_decode($resp->getResultJson(), true))->toBe(['echoed' => 'hi']);

    fclose($a);
    fclose($b);
});

it('returns false from processOne on EOF', function () {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    assert($pair !== false);
    [$a, $b] = $pair;

    $registry = new ToolRegistry([]);
    $dispatcher = new ToolDispatcher($registry, toolMeta: [], logger: new NullLogger());

    fclose($a);   // parent closes its end → worker sees EOF
    $worker = new WorkerProcess($b, $dispatcher, new NullLogger());
    expect($worker->processOne())->toBeFalse();
    fclose($b);
});
