<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Swoole;

use Psr\Log\NullLogger;
use Vested\Connect\Sdk\ConnectorApp;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ToolCallRequest;
use Vested\Connect\Sdk\Observability\Tracing;
use Vested\Connect\Sdk\Swoole\CoroutineDispatcher;
use Vested\Connect\Sdk\Swoole\OutboundChannel;
use Vested\Connect\Sdk\Tool\ToolContext;

it('dispatches a tool call inside a coroutine and pushes the response', function () {
    \Swoole\Coroutine\run(function () {
        $app = ConnectorApp::create()
            ->agent('t')
                ->withTool(
                    key: 't.k', name: 'T', description: '',
                    inputSchema:  ['type' => 'object'],
                    outputSchema: ['type' => 'object'],
                    handler: fn (array $a, ToolContext $c) => ['ok' => true],
                )
            ->endAgent()
            ->build();

        $ch = new OutboundChannel();
        $disp = new CoroutineDispatcher(
            registry:  $app->tools(),
            toolMeta:  ['t.k' => ['input_schema' => ['type' => 'object'], 'output_schema' => ['type' => 'object']]],
            outbound:  $ch,
            logger:    new NullLogger(),
            tracing:   new Tracing(null),
        );

        $req = new ToolCallRequest([
            'invocation_id' => 'i-1',
            'tool_key'      => 't.k',
            'args_json'     => '{}',
        ]);
        $disp->dispatch($req);

        // CoroutineDispatcher::dispatch spawns a coroutine; wait briefly.
        $resp = $ch->popOrNull(timeoutSeconds: 1.0);
        expect($resp)->not->toBeNull();
        $tcr = $resp->getToolCallResponse();
        expect($tcr->getInvocationId())->toBe('i-1');
        expect($tcr->getError())->toBe('');
        expect($tcr->getResultJson())->toBe('{"ok":true}');
    });
})->skip(! extension_loaded('swoole'), 'Swoole not installed');

it('uncaught handler exception becomes ToolCallResponse error', function () {
    \Swoole\Coroutine\run(function () {
        $app = ConnectorApp::create()
            ->agent('t')
                ->withTool(
                    key: 't.k', name: 'T', description: '',
                    inputSchema:  ['type' => 'object'],
                    outputSchema: ['type' => 'object'],
                    handler: fn (array $a, ToolContext $c) => throw new \RuntimeException('boom'),
                )
            ->endAgent()
            ->build();

        $ch = new OutboundChannel();
        $disp = new CoroutineDispatcher(
            registry:  $app->tools(),
            toolMeta:  ['t.k' => ['input_schema' => ['type' => 'object'], 'output_schema' => ['type' => 'object']]],
            outbound:  $ch,
            logger:    new NullLogger(),
            tracing:   new Tracing(null),
        );

        $disp->dispatch(new ToolCallRequest([
            'invocation_id' => 'i-2',
            'tool_key'      => 't.k',
            'args_json'     => '{}',
        ]));

        $resp = $ch->popOrNull(timeoutSeconds: 1.0);
        $tcr = $resp?->getToolCallResponse();
        expect($tcr?->getError())->toContain('boom');
    });
})->skip(! extension_loaded('swoole'), 'Swoole not installed');
