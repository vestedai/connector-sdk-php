<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Tool;

use Psr\Log\NullLogger;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ToolCallRequest;
use Vested\Connect\Sdk\Tool\ToolContext;
use Vested\Connect\Sdk\Tool\ToolDispatcher;
use Vested\Connect\Sdk\Tool\ToolRegistry;

it('dispatches to a closure handler and returns success', function () {
    $registry = new ToolRegistry([
        'x.y.echo' => fn (array $a, ToolContext $c) => ['echoed' => $a['s']],
    ]);
    $dispatcher = new ToolDispatcher(
        $registry,
        toolMeta: ['x.y.echo' => [
            'input_schema'  => ['type' => 'object', 'properties' => ['s' => ['type' => 'string']], 'required' => ['s']],
            'output_schema' => ['type' => 'object', 'properties' => ['echoed' => ['type' => 'string']]],
        ]],
        logger: new NullLogger(),
    );

    $req = new ToolCallRequest([
        'invocation_id' => 'inv', 'agent_key' => 'x.y', 'tool_key' => 'x.y.echo',
        'args_json' => '{"s":"hi"}', 'organization_id' => '7',
        'user_id' => '11', 'user_email' => 'u@e.com',
        'conversation_id' => 'C', 'deadline_ms' => 1000,
    ]);
    $resp = $dispatcher->dispatch($req);
    expect($resp->getInvocationId())->toBe('inv');
    expect($resp->getError())->toBe('');
    expect(json_decode($resp->getResultJson(), true))->toBe(['echoed' => 'hi']);
});

it('returns error response when args fail input schema', function () {
    $registry = new ToolRegistry([
        'x.y.echo' => fn ($a, $c) => ['echoed' => $a['s']],
    ]);
    $dispatcher = new ToolDispatcher($registry, toolMeta: [
        'x.y.echo' => [
            'input_schema'  => ['type' => 'object', 'required' => ['s']],
            'output_schema' => ['type' => 'object'],
        ],
    ], logger: new NullLogger());

    $req = new ToolCallRequest([
        'invocation_id' => 'inv', 'tool_key' => 'x.y.echo',
        'args_json' => '{}', 'organization_id' => '7',
        'user_id' => '', 'user_email' => '', 'conversation_id' => 'C',
        'agent_key' => 'x.y', 'deadline_ms' => 1000,
    ]);
    $resp = $dispatcher->dispatch($req);
    expect($resp->getError())->toContain('input_schema');
    expect($resp->getResultJson())->toBe('');
});

it('returns error response when handler throws', function () {
    $registry = new ToolRegistry([
        'x.y.crash' => fn () => throw new \RuntimeException('kaboom'),
    ]);
    $dispatcher = new ToolDispatcher($registry, toolMeta: [
        'x.y.crash' => [
            'input_schema'  => ['type' => 'object'],
            'output_schema' => ['type' => 'object'],
        ],
    ], logger: new NullLogger());

    $req = new ToolCallRequest([
        'invocation_id' => 'inv', 'tool_key' => 'x.y.crash',
        'args_json' => '{}', 'organization_id' => '7',
        'user_id' => '', 'user_email' => '', 'conversation_id' => 'C',
        'agent_key' => 'x.y', 'deadline_ms' => 1000,
    ]);
    $resp = $dispatcher->dispatch($req);
    expect($resp->getError())->toContain('kaboom');
});

it('returns error when handler return value fails output schema', function () {
    $registry = new ToolRegistry([
        'x.y.bad' => fn () => ['nope' => 1],
    ]);
    $dispatcher = new ToolDispatcher($registry, toolMeta: [
        'x.y.bad' => [
            'input_schema'  => ['type' => 'object'],
            'output_schema' => ['type' => 'object', 'required' => ['expected']],
        ],
    ], logger: new NullLogger());

    $req = new ToolCallRequest([
        'invocation_id' => 'inv', 'tool_key' => 'x.y.bad',
        'args_json' => '{}', 'organization_id' => '7',
        'user_id' => '', 'user_email' => '', 'conversation_id' => 'C',
        'agent_key' => 'x.y', 'deadline_ms' => 1000,
    ]);
    $resp = $dispatcher->dispatch($req);
    expect($resp->getError())->toContain('output_schema');
});

it('surfaces ERP identity fields from ToolCallRequest onto ToolContext', function () {
    $capturedCtx = null;
    $registry = new ToolRegistry([
        'x.y.erp' => function (array $a, ToolContext $ctx) use (&$capturedCtx): array {
            $capturedCtx = $ctx;
            return ['ok' => true];
        },
    ]);
    $dispatcher = new ToolDispatcher($registry, toolMeta: [
        'x.y.erp' => [
            'input_schema'  => ['type' => 'object'],
            'output_schema' => ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]],
        ],
    ], logger: new NullLogger());

    $req = new ToolCallRequest([
        'invocation_id'             => 'inv-erp',
        'agent_key'                 => 'x.y',
        'tool_key'                  => 'x.y.erp',
        'args_json'                 => '{}',
        'organization_id'           => '7',
        'user_id'                   => '11',
        'user_email'                => 'u@e.com',
        'conversation_id'           => 'C',
        'deadline_ms'               => 1000,
        'employee_no'               => 'EMP-001',
        'erp_identifier'            => 'SAP-USER-42',
        'erp_department_identifiers' => ['DEPT-A', 'DEPT-B'],
    ]);
    $resp = $dispatcher->dispatch($req);

    expect($resp->getError())->toBe('');
    assert($capturedCtx instanceof ToolContext);
    expect($capturedCtx->employeeNo)->toBe('EMP-001');
    expect($capturedCtx->erpIdentifier)->toBe('SAP-USER-42');
    expect($capturedCtx->erpDepartmentIdentifiers)->toBe(['DEPT-A', 'DEPT-B']);
});

it('defaults ERP fields on ToolContext when ToolCallRequest omits them', function () {
    $capturedCtx = null;
    $registry = new ToolRegistry([
        'x.y.noerp' => function (array $a, ToolContext $ctx) use (&$capturedCtx): array {
            $capturedCtx = $ctx;
            return ['ok' => true];
        },
    ]);
    $dispatcher = new ToolDispatcher($registry, toolMeta: [
        'x.y.noerp' => [
            'input_schema'  => ['type' => 'object'],
            'output_schema' => ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]],
        ],
    ], logger: new NullLogger());

    $req = new ToolCallRequest([
        'invocation_id'   => 'inv-noerp',
        'agent_key'       => 'x.y',
        'tool_key'        => 'x.y.noerp',
        'args_json'       => '{}',
        'organization_id' => '7',
        'user_id'         => '11',
        'user_email'      => 'u@e.com',
        'conversation_id' => 'C',
        'deadline_ms'     => 1000,
    ]);
    $dispatcher->dispatch($req);

    assert($capturedCtx instanceof ToolContext);
    expect($capturedCtx->employeeNo)->toBe('');
    expect($capturedCtx->erpIdentifier)->toBe('');
    expect($capturedCtx->erpDepartmentIdentifiers)->toBe([]);
});
