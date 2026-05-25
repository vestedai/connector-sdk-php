<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Tool;

use Psr\Log\NullLogger;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ToolCallRequest;
use Vested\Connect\Sdk\Observability\Tracing;
use Vested\Connect\Sdk\Tests\Unit\Observability\FakeTracer;
use Vested\Connect\Sdk\Tool\ToolContext;
use Vested\Connect\Sdk\Tool\ToolDispatcher;
use Vested\Connect\Sdk\Tool\ToolRegistry;

it('emits connector.tool_handler span when tracer is wired in', function () {
    $registry = new ToolRegistry([
        't.k' => fn (array $a, ToolContext $c) => ['ok' => true],
    ]);

    $tracer = new FakeTracer();
    $dispatcher = new ToolDispatcher(
        registry: $registry,
        toolMeta: ['t.k' => [
            'input_schema'  => ['type' => 'object'],
            'output_schema' => ['type' => 'object'],
        ]],
        logger: new NullLogger(),
        tracing: new Tracing($tracer),
    );

    $req = new ToolCallRequest([
        'invocation_id' => 'i-1',
        'tool_key'      => 't.k',
        'args_json'     => '{}',
        'agent_key'     => 't',
        'organization_id' => '',
        'user_id'       => '',
        'user_email'    => '',
        'conversation_id' => '',
        'deadline_ms'   => 1000,
    ]);
    $resp = $dispatcher->dispatch($req);

    expect($resp->getError())->toBe('');
    expect($tracer->spans)->toHaveCount(1);
    expect($tracer->spans[0]->name)->toBe('connector.tool_handler');
    expect($tracer->spans[0]->attributes['tool.key'])->toBe('t.k');
    expect($tracer->spans[0]->attributes['invocation.id'])->toBe('i-1');
    expect($tracer->spans[0]->ended)->toBeTrue();
});
