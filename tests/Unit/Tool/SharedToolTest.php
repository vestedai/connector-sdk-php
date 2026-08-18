<?php

declare(strict_types=1);

use Vested\Connect\Sdk\Agent\AgentBuilder;
use Vested\Connect\Sdk\Exception\ConfigException;
use Vested\Connect\Sdk\Tool\ToolRegistry;

/**
 * Sharing one tool across several agents.
 *
 * PHP is the odd SDK here: it already binds explicitly (#[Tool(agentKey: …)]),
 * and AgentBuilder::toDeclaration() nests each agent's tools inside it — so
 * expanding a shared tool into every bound agent moves BOTH the Register frame
 * and the fingerprint, with no separate fingerprint change needed.
 *
 * What it did NOT allow was the same key under two agents at all.
 */
function sharedToolHandler(string $marker): Closure
{
    return fn (array $args, $ctx): array => ['marker' => $marker];
}

function agentWithTool(string $agentKey, string $toolKey, Closure $handler): AgentBuilder
{
    $b = new AgentBuilder($agentKey);
    $b->withModel('openai', 'gpt-4o')
        ->withTool(
            key: $toolKey,
            name: $toolKey,
            description: 'd',
            inputSchema: ['type' => 'object'],
            outputSchema: ['type' => 'object'],
            handler: $handler,
        );

    return $b;
}

it('registers one handler bound to several agents', function () {
    // The SAME handler instance under the same key on two agents — a shared
    // tool, which is exactly what the duplicate guard used to forbid.
    $handler = sharedToolHandler('shared');

    $registry = ToolRegistry::fromAgents([
        agentWithTool('erp.data', 'erp.data.run_sql', $handler),
        agentWithTool('erp.retail', 'erp.data.run_sql', $handler),
    ]);

    expect($registry->has('erp.data.run_sql'))->toBeTrue();
    expect($registry->resolve('erp.data.run_sql'))->toBe($handler);
});

it('still refuses the same key with two different handlers', function () {
    // The real hazard the guard was written for: dispatch resolves by tool key
    // alone, so two DIFFERENT handlers under one key cannot be told apart.
    ToolRegistry::fromAgents([
        agentWithTool('erp.data', 'erp.data.run_sql', sharedToolHandler('a')),
        agentWithTool('erp.retail', 'erp.data.run_sql', sharedToolHandler('b')),
    ]);
})->throws(ConfigException::class, 'different handlers');

it('binds withSharedTool() to each named agent', function () {
    $app = \Vested\Connect\Sdk\ConnectorApp::create();
    $app->agent('erp.data')->withModel('openai', 'gpt-4o')->endAgent();
    $app->agent('erp.retail')->withModel('openai', 'gpt-4o')->endAgent();

    $app->withSharedTool(
        key: 'erp.shared.run_sql',
        agents: ['erp.data', 'erp.retail'],
        name: 'run_sql',
        description: 'd',
        inputSchema: ['type' => 'object'],
        outputSchema: ['type' => 'object'],
        handler: sharedToolHandler('shared'),
    );

    $keys = [];
    foreach ($app->build()->agents()->declarations() as $decl) {
        $keys[$decl['key']] = array_column($decl['tools'], 'key');
    }

    expect($keys['erp.data'])->toBe(['erp.shared.run_sql']);
    expect($keys['erp.retail'])->toBe(['erp.shared.run_sql']);
});

it('binds withSharedTool("*") to every declared agent', function () {
    $app = \Vested\Connect\Sdk\ConnectorApp::create();
    foreach (['erp.data', 'erp.retail', 'erp.sales'] as $k) {
        $app->agent($k)->withModel('openai', 'gpt-4o')->endAgent();
    }

    $app->withSharedTool(
        key: 'erp.shared.ping',
        agents: '*',
        name: 'ping',
        description: 'd',
        inputSchema: ['type' => 'object'],
        outputSchema: ['type' => 'object'],
        handler: sharedToolHandler('shared'),
    );

    foreach ($app->build()->agents()->declarations() as $decl) {
        expect(array_column($decl['tools'], 'key'))->toBe(['erp.shared.ping']);
    }
});

it('refuses withSharedTool() naming an agent that is not declared', function () {
    $app = \Vested\Connect\Sdk\ConnectorApp::create();
    $app->agent('erp.data')->withModel('openai', 'gpt-4o')->endAgent();

    $app->withSharedTool(
        key: 'erp.shared.run_sql',
        agents: ['erp.data', 'erp.nope'],
        name: 'run_sql',
        description: 'd',
        inputSchema: ['type' => 'object'],
        outputSchema: ['type' => 'object'],
        handler: sharedToolHandler('shared'),
    );
})->throws(ConfigException::class, 'erp.nope');

it('refuses withSharedTool() combining "*" with explicit keys', function () {
    $app = \Vested\Connect\Sdk\ConnectorApp::create();
    $app->agent('erp.data')->withModel('openai', 'gpt-4o')->endAgent();

    $app->withSharedTool(
        key: 'erp.shared.run_sql',
        agents: ['*', 'erp.data'],
        name: 'run_sql',
        description: 'd',
        inputSchema: ['type' => 'object'],
        outputSchema: ['type' => 'object'],
        handler: sharedToolHandler('shared'),
    );
})->throws(ConfigException::class);

it('keeps a shared tool in every bound agent declaration', function () {
    // toDeclaration()['tools'] is what reaches the Register frame AND the
    // fingerprint, so the tool must appear under each agent that has it.
    $handler = sharedToolHandler('shared');

    $data   = agentWithTool('erp.data', 'erp.data.run_sql', $handler)->toDeclaration();
    $retail = agentWithTool('erp.retail', 'erp.data.run_sql', $handler)->toDeclaration();

    expect(array_column($data['tools'], 'key'))->toBe(['erp.data.run_sql']);
    expect(array_column($retail['tools'], 'key'))->toBe(['erp.data.run_sql']);
});

/*
 * Learned the hard way on 2026-08-18: a tool shared across every agent pushed
 * ONE agent from 30 tools to 31, one over that connector's hub limit, so the
 * hub rejected the whole Register — and with no declaration, BOTH the schema
 * gate and the credential gate refused every call for ~1 hour, reported as
 * `lookup_failed` ("try again shortly"), advice that could never work.
 *
 * The limit cannot be checked at build(): it is per-connector and arrives in
 * HelloAck, after the worker has already dialled.
 */
it('accepts a declaration at or under the hub tool limit', function () {
    $app = \Vested\Connect\Sdk\ConnectorApp::create();
    $app->agent('erp.data')->withModel('openai', 'gpt-4o')
        ->withTool(key: 'erp.data.a', name: 'a', description: 'd',
            inputSchema: ['type' => 'object'], outputSchema: ['type' => 'object'],
            handler: sharedToolHandler('x'))
        ->withTool(key: 'erp.data.b', name: 'b', description: 'd',
            inputSchema: ['type' => 'object'], outputSchema: ['type' => 'object'],
            handler: sharedToolHandler('y'))
        ->endAgent();
    $app->build();

    // Under, then EXACTLY at: the hub refuses 31 against 30, so 2 against a
    // limit of 2 must pass. Off-by-one here grounds a connector the hub accepts.
    \Vested\Connect\Sdk\Hub\StreamHandler::assertHubLimits($app, 3);
    \Vested\Connect\Sdk\Hub\StreamHandler::assertHubLimits($app, 2);
})->throwsNoExceptions();

it('refuses a declaration over the hub tool limit, naming the agent', function () {
    $app = \Vested\Connect\Sdk\ConnectorApp::create();
    $app->agent('erp.retail')->withModel('openai', 'gpt-4o')
        ->withTool(key: 'erp.retail.a', name: 'a', description: 'd',
            inputSchema: ['type' => 'object'], outputSchema: ['type' => 'object'],
            handler: sharedToolHandler('x'))
        ->withTool(key: 'erp.retail.b', name: 'b', description: 'd',
            inputSchema: ['type' => 'object'], outputSchema: ['type' => 'object'],
            handler: sharedToolHandler('y'))
        ->endAgent();
    $app->build();

    \Vested\Connect\Sdk\Hub\StreamHandler::assertHubLimits($app, 1);
})->throws(ConfigException::class, 'erp.retail');

it('treats a hub tool limit of 0 as unknown', function () {
    // proto3 defaults uint32 to 0 and an older hub sends nothing; reading that
    // as a real ceiling would ground every connector — this check inverted.
    $app = \Vested\Connect\Sdk\ConnectorApp::create();
    $app->agent('erp.data')->withModel('openai', 'gpt-4o')
        ->withTool(key: 'erp.data.a', name: 'a', description: 'd',
            inputSchema: ['type' => 'object'], outputSchema: ['type' => 'object'],
            handler: sharedToolHandler('x'))
        ->endAgent();
    $app->build();

    \Vested\Connect\Sdk\Hub\StreamHandler::assertHubLimits($app, 0);
})->throwsNoExceptions();
