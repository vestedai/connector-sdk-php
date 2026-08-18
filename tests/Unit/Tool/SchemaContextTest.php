<?php

declare(strict_types=1);

use Vested\Connect\Sdk\Tool\SchemaContext;
use Vested\Connect\Sdk\Tool\SchemaContextTable;
use Vested\Connect\Sdk\Tool\ToolContext;

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

it('is NULL on the context when the core sent none — which is not an empty table list', function () {
    // The distinction the whole design rests on. A handler that treats null as
    // "no tables touched" builds a permission layer that approves everything.
    $none = null;
    $empty = new SchemaContext(tables: [], hasStar: false, gateMode: 'enforce');

    expect($none)->toBeNull()
        ->and($empty)->not->toBeNull()
        ->and($empty->tables)->toBe([]);
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
