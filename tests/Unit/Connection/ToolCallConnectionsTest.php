<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Connection;

// The SDK half of the platform round trip: a ToolCallRequest carrying a tenant
// connection, as Plan C1's hub builds it, rebuilt from its serialized bytes and
// dispatched through the real ToolDispatcher to a handler that opens it. The
// envelope is Plan B's vector, so Laravel's sealer, the hub's wire and this
// opener are pinned to one another.

use Psr\Log\NullLogger;
use Vested\Connect\Sdk\Agent\AgentBuilder;
use Vested\Connect\Sdk\Connection\ConnectionSet;
use Vested\Connect\Sdk\Connection\ConnectionUnavailable;
use Vested\Connect\Sdk\Connection\PlatformConnectionOpener;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\TenantConnection;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ToolCallRequest;
use Vested\Connect\Sdk\Tool\ToolContext;
use Vested\Connect\Sdk\Tool\ToolDispatcher;
use Vested\Connect\Sdk\Tool\ToolRegistry;

/** @return array{vector: array<string, mixed>, pc: string, org: string, connection: string} */
function platformVector(): array
{
    $f = json_decode((string) file_get_contents(__DIR__.'/../../../testdata/platform-connection-envelope-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
    $v = $f['vectors'][0];
    preg_match('/^platform:(\d+)\|org:(\d+)\|connection:(\d+)\|v\d+$/', $v['aad'], $m);

    return ['vector' => $v, 'pc' => $m[1], 'org' => $m[2], 'connection' => $m[3]];
}

/** Dispatch one call to a handler over a frame rebuilt from bytes. */
function dispatchWith(\Closure $handler, string $organizationId, array $connections, ?PlatformConnectionOpener $opener, string $pc): array
{
    $agent = (new AgentBuilder('mkt.ga4'))->withModel('openai', 'gpt-4o')->withInstruction('x')
        ->withTool(key: 'mkt.ga4.report', name: 'R', description: '', inputSchema: ['type' => 'object'],
            outputSchema: ['type' => 'object'], handler: $handler);

    $dispatcher = new ToolDispatcher(
        registry: ToolRegistry::fromAgents(['mkt.ga4' => $agent]),
        toolMeta: ['mkt.ga4.report' => ['input_schema' => ['type' => 'object'], 'output_schema' => ['type' => 'object']]],
        logger: new NullLogger(),
        connectorId: fn (): string => $pc,
        connectionOpener: $opener,
    );

    $req = new ToolCallRequest(['invocation_id' => 'inv-1', 'agent_key' => 'mkt.ga4', 'tool_key' => 'mkt.ga4.report',
        'args_json' => '{}', 'organization_id' => $organizationId]);
    $req->setConnections($connections);

    $wire = new ToolCallRequest();
    $wire->mergeFromString($req->serializeToString());

    $resp = $dispatcher->dispatch($wire);

    return ['error' => $resp->getError(), 'result' => json_decode($resp->getResultJson() ?: 'null', true)];
}

function googleWire(array $envelope, string $connectionId, string $resourceJson = '{"id":"properties/1","label":"Main","meta":{"currency":"SAR"}}'): TenantConnection
{
    return new TenantConnection([
        'profile' => 'google', 'label' => '', 'connection_id' => $connectionId,
        'resource_json' => $resourceJson, 'envelope_json' => (string) json_encode($envelope),
    ]);
}

it('hands a tool the opened tenant connection and its resource', function () {
    ['vector' => $v, 'pc' => $pc, 'org' => $org, 'connection' => $conn] = platformVector();

    $out = dispatchWith(
        fn (array $a, ToolContext $ctx) => [
            'credential' => $ctx->connections()->one('google')->credential(),
            'resource'   => $ctx->connections()->one('google')->resource(),
        ],
        $org, [googleWire($v['envelope'], $conn)], new PlatformConnectionOpener($v['private_key_pem']), $pc,
    );

    expect($out['error'])->toBe('')
        ->and($out['result']['credential'])->toEqual($v['plaintext'])
        ->and($out['result']['resource'])->toBe(['id' => 'properties/1', 'label' => 'Main', 'meta' => ['currency' => 'SAR']]);
});

it('refuses a connection replayed into another tenant\'s call', function () {
    ['vector' => $v, 'pc' => $pc, 'org' => $org, 'connection' => $conn] = platformVector();

    $out = dispatchWith(
        fn (array $a, ToolContext $ctx) => ['credential' => $ctx->connections()->one('google')->credential()],
        (string) ((int) $org + 1), [googleWire($v['envelope'], $conn)], new PlatformConnectionOpener($v['private_key_pem']), $pc,
    );

    expect($out['error'])->toContain('identity mismatch');
});

it('decrypts nothing a handler does not ask for', function () {
    ['pc' => $pc, 'org' => $org] = platformVector();

    $out = dispatchWith(
        fn (array $a, ToolContext $ctx) => ['resource' => $ctx->connections()->one('google')->resource()['id']],
        $org, [googleWire(['alg' => 'garbage'], '3')], null, $pc,
    );

    expect($out['error'])->toBe('')->and($out['result']['resource'])->toBe('properties/1');
});

it('says clearly when a connection is missing, ambiguous or unopenable', function () {
    $set = new ConnectionSet();
    expect(fn () => $set->one('google'))->toThrow(ConnectionUnavailable::class, "No 'google' connection")
        ->and($set->all('google'))->toBe([])
        ->and($set->has('google'))->toBeFalse();

    ['pc' => $pc, 'org' => $org] = platformVector();
    $out = dispatchWith(
        fn (array $a, ToolContext $ctx) => ['count' => count($ctx->connections()->all('google')), 'one' => $ctx->connections()->one('google')],
        $org, [googleWire(['v' => 1], '3'), googleWire(['v' => 1], '4')], null, $pc,
    );
    expect($out['error'])->toContain("use all('google')");

    $out = dispatchWith(
        fn (array $a, ToolContext $ctx) => ['credential' => $ctx->connections()->one('google')->credential()],
        $org, [googleWire(['v' => 1], '3')], null, $pc,
    );
    expect($out['error'])->toContain('VESTED_CREDENTIAL_PRIVATE_KEY');
});

it('finds a many-bound connection by label', function () {
    ['pc' => $pc, 'org' => $org] = platformVector();
    $ksa = new TenantConnection(['profile' => 'moengage', 'label' => 'sa', 'connection_id' => '7', 'resource_json' => '{}', 'envelope_json' => '{}']);
    $uae = new TenantConnection(['profile' => 'moengage', 'label' => 'ae', 'connection_id' => '8', 'resource_json' => '{}', 'envelope_json' => '{}']);

    $out = dispatchWith(
        fn (array $a, ToolContext $ctx) => ['id' => $ctx->connections()->byLabel('moengage', 'ae')->connectionId],
        $org, [$ksa, $uae], null, $pc,
    );

    expect($out['result']['id'])->toBe('8');
});

it('gives a context built without connections an empty set', function () {
    $ctx = new ToolContext(
        invocationId: 'i', organizationId: '1', userId: '', userEmail: '', conversationId: '',
        agentKey: 'a', toolKey: 't', deadlineMs: 1000, logger: new NullLogger(), invokedAt: new \DateTimeImmutable(),
    );

    expect($ctx->connections()->has('google'))->toBeFalse();
});
