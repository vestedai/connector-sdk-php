<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Hub;

// ---------------------------------------------------------------------------
// Task 9 (L3e): the forcing function. The SDK throws AT BOOTSTRAP — on
// ConnectorApp::build(), before the worker ever dials the hub — when a
// relational source declares more than one scope without naming a
// default_scope, or names a default_scope that is not one of the declared
// scopes.
//
// Same seam and same reasoning as the credential keyring precedent
// (ConnectorApp::withCredentialHandler(), which throws at startup if a
// credential handler is registered without a private key "rather than
// failing every check later with a puzzling message"): this failure belongs
// on the connector author's deploy, not on a model's tool call at 00:30 in
// production.
//
// No top-level named helpers here beyond the two below, which are unique to
// this file (RegisterDeclarationsTest.php's helpers are named hubDecl*) —
// Pest loads every test file into one process, and a second bare top-level
// `function` with a colliding name fatals the entire suite at exit 255.
// ---------------------------------------------------------------------------

use Vested\Connect\Sdk\Attribute\RelationalSource;
use Vested\Connect\Sdk\ConnectorApp;
use Vested\Connect\Sdk\Schema\CanonicalSchema;
use Vested\Connect\Sdk\Schema\RelationalSchemaProvider;
use Vested\Connect\Sdk\Tool\ToolContext;

/**
 * Base for the fixtures below. Not itself attributed: PHP attribute
 * arguments must be constant expressions, so `defaultScope` cannot be a
 * constructor parameter forwarded into #[RelationalSource] — each concrete
 * fixture below hardcodes its own literal defaultScope instead.
 */
abstract class ScopeTestProviderBase implements RelationalSchemaProvider
{
    /** @param  list<string>  $scopesToReturn */
    public function __construct(private readonly array $scopesToReturn) {}

    public function scopes(): array
    {
        return $this->scopesToReturn;
    }

    public function describe(string $scopeKey): CanonicalSchema
    {
        return new CanonicalSchema(entities: [], relations: []);
    }

    public function catalogFingerprint(): string
    {
        return 'catalog-hash';
    }
}

#[RelationalSource(engine: 'mysql', describeTool: 'erp.describe_schema', queryTool: 'erp.query_sql', sqlArg: 'sql')]
final class ScopeTestProviderNoDefault extends ScopeTestProviderBase {}

#[RelationalSource(
    engine: 'mysql', describeTool: 'erp.describe_schema', queryTool: 'erp.query_sql', sqlArg: 'sql',
    defaultScope: 'production',
)]
final class ScopeTestProviderDefaultProduction extends ScopeTestProviderBase {}

#[RelationalSource(
    engine: 'mysql', describeTool: 'erp.describe_schema', queryTool: 'erp.query_sql', sqlArg: 'sql',
    defaultScope: 'erp_middleware',
)]
final class ScopeTestProviderDefaultErpMiddleware extends ScopeTestProviderBase {}

/** A minimal connector declaring the two tools the provider's attribute names. */
function scopeTestApp(RelationalSchemaProvider $provider): ConnectorApp
{
    return ConnectorApp::create()
        ->agent('erp')
            ->withTool(
                key: 'erp.describe_schema', name: 'Describe schema', description: '',
                inputSchema: ['type' => 'object', 'properties' => []],
                outputSchema: ['type' => 'object'],
                handler: fn (array $a, ToolContext $c) => [],
            )
            ->withTool(
                key: 'erp.query_sql', name: 'Query SQL', description: '',
                inputSchema: ['type' => 'object', 'properties' => ['sql' => ['type' => 'string']]],
                outputSchema: ['type' => 'object'],
                handler: fn (array $a, ToolContext $c) => [],
            )
        ->endAgent()
        ->withRelationalSchemaProvider($provider)
        ->build();
}

it('throws at bootstrap when several scopes are declared without a default', function () {
    expect(fn () => scopeTestApp(new ScopeTestProviderNoDefault(['production', 'erp_middleware_production'])))
        ->toThrow(\InvalidArgumentException::class, 'default_scope');
});

it('throws when default_scope is not one of the declared scopes', function () {
    expect(fn () => scopeTestApp(new ScopeTestProviderDefaultErpMiddleware(['production'])))
        ->toThrow(\InvalidArgumentException::class, 'default_scope');
});

it('accepts one scope with no default', function () {
    expect(fn () => scopeTestApp(new ScopeTestProviderNoDefault(['ASG'])))
        ->not->toThrow(\InvalidArgumentException::class);
});

it('accepts a declaration with no scopes at all', function () {
    // Backward compatibility: today's connectors declare neither field.
    expect(fn () => scopeTestApp(new ScopeTestProviderNoDefault([])))
        ->not->toThrow(\InvalidArgumentException::class);
});

it('wires scopes and default_scope onto the wire message', function () {
    $app = scopeTestApp(new ScopeTestProviderDefaultProduction(['production', 'erp_middleware_production']));

    $bytes = \Vested\Connect\Sdk\Hub\StreamHandler::buildRegister($app)->serializeToString();
    $parsed = new \Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectorMsg();
    $parsed->mergeFromString($bytes);

    $rel = $parsed->getRegister()?->getRelationalSource();
    expect($rel)->not->toBeNull();
    assert($rel !== null);

    expect(iterator_to_array($rel->getScopes()))->toBe(['production', 'erp_middleware_production']);
    expect($rel->getDefaultScope())->toBe('production');
});
