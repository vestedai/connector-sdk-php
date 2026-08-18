<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\SchemaContext as ProtoSchemaContext;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ToolCallRequest;
use Vested\Connect\Sdk\Tool\SchemaContext;
use Vested\Connect\Sdk\Tool\SchemaContextTable;
use Vested\Connect\Sdk\Tool\ToolContext;
use Vested\Connect\Sdk\Tool\ToolDispatcher;
use Vested\Connect\Sdk\Tool\ToolRegistry;

it('reads the tables the core resolved', function () {
    $ctx = new SchemaContext(
        tables: [new SchemaContextTable('Item Ledger Entry', 'ASG', 'table', ['ASG$Item Ledger Entry$437dbf0e'])],
        hasStar: false,
        gateMode: 'enforce',
    );

    expect($ctx->tables[0]->logicalName)->toBe('Item Ledger Entry')
        ->and($ctx->tables[0]->physical)->toBe(['ASG$Item Ledger Entry$437dbf0e'])
        ->and($ctx->gateMode)->toBe('enforce');
});

it('maps a PRESENT schema_context with an empty table list through the real dispatcher — distinct from absent', function () {
    // Final whole-branch review, MINOR 6: the version of this test that used
    // to live here asserted `$none = null; expect($none)->toBeNull()`, which
    // exercises zero SDK code — it is trivially true of any two PHP variables
    // and would pass unchanged even if ToolDispatcher::dispatch stopped
    // mapping schema_context at all. This is the file a connector author
    // reads as the worked example, so it now drives the REAL mapping
    // (ToolDispatcher::dispatch -> ToolDispatcher::mapSchemaContext), the way
    // ToolDispatcherTest.php's schema_context tests already do — for the one
    // state those do not cover: PRESENT but Tables EMPTY (the gate decided
    // and resolved nothing), which is the state the whole absent-vs-empty
    // guarantee is about and had no coverage of its own here.
    $capturedCtx = null;
    $registry = new ToolRegistry([
        'x.y.emptysc' => function (array $a, ToolContext $ctx) use (&$capturedCtx): array {
            $capturedCtx = $ctx;

            return ['ok' => true];
        },
    ]);
    $dispatcher = new ToolDispatcher($registry, toolMeta: [
        'x.y.emptysc' => [
            'input_schema'  => ['type' => 'object'],
            'output_schema' => ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]],
        ],
    ], logger: new NullLogger());

    $protoSchemaContext = new ProtoSchemaContext([
        'tables'    => [],
        'has_star'  => false,
        'gate_mode' => 'enforce',
    ]);

    $req = new ToolCallRequest([
        'invocation_id'   => 'inv-emptysc',
        'agent_key'       => 'x.y',
        'tool_key'        => 'x.y.emptysc',
        'args_json'       => '{}',
        'organization_id' => '7',
        'user_id'         => '11',
        'user_email'      => 'u@example.com',
        'conversation_id' => 'C',
        'deadline_ms'     => 1000,
        'schema_context'  => $protoSchemaContext,
    ]);
    $dispatcher->dispatch($req);

    assert($capturedCtx instanceof ToolContext);
    $sc = $capturedCtx->schemaContext;
    assert($sc instanceof SchemaContext);
    expect($sc)->not->toBeNull('a present context must not collapse to null')
        ->and($sc->tables)->toBe([], 'a genuinely-decided empty resolution is still a present, empty list')
        ->and($sc->gateMode)->toBe('enforce');
});

it('defaults ToolContext::$schemaContext to null when not supplied', function () {
    $ctx = new ToolContext(
        invocationId: 'inv-1', organizationId: '7', userId: '11', userEmail: 'u@example.com',
        conversationId: 'C1', agentKey: 'x.y', toolKey: 'x.y.t', deadlineMs: 1000,
        logger: new Psr\Log\NullLogger(), invokedAt: new DateTimeImmutable(),
    );

    expect($ctx->schemaContext)->toBeNull();
});

it('carries a present SchemaContext through ToolContext when supplied', function () {
    $schema = new SchemaContext(
        tables: [new SchemaContextTable('Item Ledger Entry', 'ASG', 'table', ['ASG$Item Ledger Entry$437dbf0e'])],
        hasStar: true,
        gateMode: 'observe',
    );

    $ctx = new ToolContext(
        invocationId: 'inv-2', organizationId: '7', userId: '11', userEmail: 'u@example.com',
        conversationId: 'C2', agentKey: 'x.y', toolKey: 'x.y.t', deadlineMs: 1000,
        logger: new Psr\Log\NullLogger(), invokedAt: new DateTimeImmutable(),
        schemaContext: $schema,
    );

    expect($ctx->schemaContext)->toBe($schema)
        ->and($schema->hasStar)->toBeTrue()
        ->and($schema->gateMode)->toBe('observe');
});
