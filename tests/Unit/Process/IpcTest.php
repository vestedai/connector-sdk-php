<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Process;

use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ToolCallRequest;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ToolCallResponse;
use Vested\Connect\Sdk\Process\Ipc;

it('round-trips a ToolCallRequest over a socketpair', function () {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    assert($pair !== false);
    [$a, $b] = $pair;

    $req = new ToolCallRequest([
        'invocation_id'   => 'inv-1',
        'agent_key'       => 'x.y',
        'tool_key'        => 'x.y.t',
        'args_json'       => '{"q":"hi"}',
        'organization_id' => '7',
        'user_id'         => '11',
        'conversation_id' => 'C1',
        'deadline_ms'     => 1000,
        'user_email'      => 'u@e.com',
    ]);

    Ipc::writeMessage($a, $req);

    $received = Ipc::readMessage($b, ToolCallRequest::class);
    expect($received)->toBeInstanceOf(ToolCallRequest::class);
    assert($received !== null);
    expect($received->getInvocationId())->toBe('inv-1');
    expect($received->getToolKey())->toBe('x.y.t');
    expect($received->getUserEmail())->toBe('u@e.com');

    fclose($a);
    fclose($b);
});

it('returns null on EOF', function () {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    assert($pair !== false);
    [$a, $b] = $pair;
    fclose($a);
    $result = Ipc::readMessage($b, ToolCallResponse::class);
    expect($result)->toBeNull();
    fclose($b);
});
