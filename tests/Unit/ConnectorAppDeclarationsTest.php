<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit;

// ---------------------------------------------------------------------------
// The declaration MECHANISM, asserted at the property level: what
// ConnectorApp derives from the attributes on a connector's own classes, and
// what it refuses to start with.
//
// These tests deliberately stop at the ConnectorApp boundary. They say nothing
// about whether the declarations reach the wire — that is
// tests/Unit/Hub/RegisterDeclarationsTest.php, and the split is the point:
// delete the emission from StreamHandler and every test in THIS file stays
// green while the round-trip test goes red.
// ---------------------------------------------------------------------------

use Vested\Connect\Sdk\Attribute\CredentialField;
use Vested\Connect\Sdk\Attribute\CredentialSchema;
use Vested\Connect\Sdk\Attribute\RelationalSource;
use Vested\Connect\Sdk\ConnectorApp;
use Vested\Connect\Sdk\Credential\CredentialContext;
use Vested\Connect\Sdk\Credential\CredentialValidation;
use Vested\Connect\Sdk\Credential\UserCredentialHandler;
use Vested\Connect\Sdk\Exception\ConfigException;
use Vested\Connect\Sdk\Schema\CanonicalSchema;
use Vested\Connect\Sdk\Schema\RelationalSchemaProvider;
use Vested\Connect\Sdk\Tool\ToolContext;

const DECL_TEST_KEY = '-----BEGIN PRIVATE KEY-----test-----END PRIVATE KEY-----';

/** Handler with no attributes at all — the pre-declarations shape. */
function declBareHandler(): UserCredentialHandler
{
    return new class implements UserCredentialHandler {
        /** @param array<string, string> $credential */
        public function validate(CredentialContext $ctx, array $credential): CredentialValidation
        {
            return CredentialValidation::ok();
        }

        /** @param array<string, string> $credential */
        public function revoke(CredentialContext $ctx, array $credential): void {}
    };
}

/** A fully annotated handler, written to be SUBCLASSED by the inheritance test. */
#[CredentialSchema(kind: 'basic', title: 'Al-Saif ERP account')]
#[CredentialField(key: 'username')]
class AppDeclAnnotatedBaseHandler implements UserCredentialHandler
{
    /** @param array<string, string> $credential */
    public function validate(CredentialContext $ctx, array $credential): CredentialValidation
    {
        return CredentialValidation::ok();
    }

    /** @param array<string, string> $credential */
    public function revoke(CredentialContext $ctx, array $credential): void {}
}

/** Provider with no attributes at all. */
function declBareProvider(): RelationalSchemaProvider
{
    return new class implements RelationalSchemaProvider {
        public function scopes(): array
        {
            return [];
        }

        public function describe(string $scopeKey): CanonicalSchema
        {
            return new CanonicalSchema(entities: [], relations: []);
        }

        public function catalogFingerprint(): string
        {
            return 'fp';
        }
    };
}

/** An app with the two tools a relational source names, ready for declarations. */
function declApp(): ConnectorApp
{
    return ConnectorApp::create()
        ->agent('erp')
            ->withTool(
                key: 'erp.describe_schema', name: 'Describe', description: '',
                inputSchema: ['type' => 'object'], outputSchema: ['type' => 'object'],
                handler: fn (array $a, ToolContext $c) => [],
            )
            ->withTool(
                key: 'erp.query_sql', name: 'Query', description: '',
                inputSchema: ['type' => 'object', 'properties' => ['sql' => ['type' => 'string']]],
                outputSchema: ['type' => 'object'],
                handler: fn (array $a, ToolContext $c) => [],
            )
        ->endAgent();
}

/**
 * Same shape as declApp(), except the query tool also accepts a 'params'
 * argument — needed only by the paramsArg tests below. Kept separate from
 * declApp() rather than adding 'params' there: several existing tests assert
 * the query tool's argument list is exactly ['sql'], and widening the shared
 * fixture would break that precondition for reasons unrelated to what they test.
 */
function declAppWithParamsArg(): ConnectorApp
{
    return ConnectorApp::create()
        ->agent('erp')
            ->withTool(
                key: 'erp.describe_schema', name: 'Describe', description: '',
                inputSchema: ['type' => 'object'], outputSchema: ['type' => 'object'],
                handler: fn (array $a, ToolContext $c) => [],
            )
            ->withTool(
                key: 'erp.query_sql', name: 'Query', description: '',
                inputSchema: [
                    'type'       => 'object',
                    'properties' => [
                        'sql'    => ['type' => 'string'],
                        'params' => ['type' => 'object'],
                    ],
                ],
                outputSchema: ['type' => 'object'],
                handler: fn (array $a, ToolContext $c) => [],
            )
        ->endAgent();
}

// ---------------------------------------------------------------------------
// What gets derived
// ---------------------------------------------------------------------------

it('derives the credential form from the attributes on the handler class', function () {
    $handler = new
    #[CredentialSchema(kind: 'token', title: 'Al-Saif ERP account', helpText: 'Ask IT.')]
    #[CredentialField(key: 'username', label: 'ERP username', placeholder: 'you@alsaif')]
    #[CredentialField(key: 'password', type: 'password')]
    #[CredentialField(key: 'company', type: 'select', required: false, options: ['KSA', 'UAE'])]
    class implements UserCredentialHandler {
        /** @param array<string, string> $credential */
        public function validate(CredentialContext $ctx, array $credential): CredentialValidation
        {
            return CredentialValidation::ok();
        }

        /** @param array<string, string> $credential */
        public function revoke(CredentialContext $ctx, array $credential): void {}
    };

    $decl = declApp()->withCredentialHandler($handler, [DECL_TEST_KEY])->build()->credentialSchemaDeclaration();

    expect($decl)->toBe([
        'kind'      => 'token',
        'title'     => 'Al-Saif ERP account',
        'help_text' => 'Ask IT.',
        'fields'    => [
            // label defaults to the key; type defaults to text; required to true
            ['key' => 'username', 'label' => 'ERP username', 'type' => 'text',     'required' => true,  'placeholder' => 'you@alsaif', 'options' => []],
            ['key' => 'password', 'label' => 'password',     'type' => 'password', 'required' => true,  'placeholder' => '',           'options' => []],
            ['key' => 'company',  'label' => 'company',      'type' => 'select',   'required' => false, 'placeholder' => '',           'options' => ['KSA', 'UAE']],
        ],
    ]);
});

it('derives the relational source from the attribute on the provider class, with no fingerprint in it', function () {
    // No fingerprint: it is read live when Register is built. A value captured
    // here would be stale by definition, and a stale fingerprint tells the core
    // "nothing changed, do not re-extract".
    $provider = new
    #[RelationalSource(engine: 'mysql', describeTool: 'erp.describe_schema', queryTool: 'erp.query_sql', sqlArg: 'sql')]
    class implements RelationalSchemaProvider {
        public function scopes(): array
        {
            return ['alsaif'];
        }

        public function describe(string $scopeKey): CanonicalSchema
        {
            return new CanonicalSchema(entities: [], relations: []);
        }

        public function catalogFingerprint(): string
        {
            return 'fp-live';
        }
    };

    $app = declApp()->withRelationalSchemaProvider($provider)->build();

    expect($app->relationalSourceDeclaration())->toBe([
        'engine'        => 'mysql',
        'describe_tool' => 'erp.describe_schema',
        'query_tool'    => 'erp.query_sql',
        'sql_arg'       => 'sql',
        // Not declared on this provider — proves omitting paramsArg is legal
        // (unlike sqlArg) and defaults to '', and that a source taking no
        // parameters still boots.
        'params_arg'    => '',
        // The attribute declares no defaultScope, yet the SOLE scope is what
        // ships. With one scope the two are the same instruction — the core
        // narrows unqualified names to `alsaif` where it would otherwise
        // resolve them by uniqueness across a one-entry pool — so this is a
        // wire change with no behavioural one, and it makes the declaration
        // say out loud where an unqualified name lands. erp_bc (one scope,
        // `ASG`, no declared default) is the live connector this affects.
        'default_scope' => 'alsaif',
        'scopes'        => ['alsaif'],
    ]);
    expect($app->relationalSchemaProvider())->toBe($provider);
});

it('declares neither for a connector that registers neither', function () {
    $app = declApp()->build();

    expect($app->credentialSchemaDeclaration())->toBeNull();
    expect($app->relationalSourceDeclaration())->toBeNull();
    expect($app->relationalSchemaProvider())->toBeNull();
});

it('derives declarations registered AFTER build(), not only before it', function () {
    // build() is normally last, but nothing enforces it. A handler registered
    // afterwards must not reach Register with an underived declaration.
    $provider = new
    #[RelationalSource(engine: 'mysql', describeTool: 'erp.describe_schema', queryTool: 'erp.query_sql', sqlArg: 'sql')]
    class implements RelationalSchemaProvider {
        public function scopes(): array
        {
            return [];
        }

        public function describe(string $scopeKey): CanonicalSchema
        {
            return new CanonicalSchema(entities: [], relations: []);
        }

        public function catalogFingerprint(): string
        {
            return 'fp';
        }
    };

    $app = declApp()->build()->withRelationalSchemaProvider($provider);

    expect($app->relationalSourceDeclaration())->not->toBeNull();
    expect($app->relationalSourceDeclaration()['engine'] ?? null)->toBe('mysql');
});

// ---------------------------------------------------------------------------
// What it refuses to start with
// ---------------------------------------------------------------------------

it('refuses a credential handler that carries no #[CredentialSchema]', function () {
    // Registering a handler is not passive. With no credential_schema on
    // Register, NONE of this connector's tools are gated, so every call keeps
    // running as the connector's own shared account and the downstream audit
    // log names a robot instead of a person. A warning would be feeble too:
    // ConnectorApp's logger defaults to NullLogger.
    expect(fn () => declApp()->withCredentialHandler(declBareHandler(), [DECL_TEST_KEY])->build())
        ->toThrow(ConfigException::class, 'carries no #[CredentialSchema]');
});

it('tells a handler that inherits its attribute that PHP does not inherit attributes', function () {
    // PHP class attributes are NOT inherited, and getAttributes() reports only
    // the class's own — so a subclass of an annotated handler declares nothing
    // while looking annotated at the call site. Nothing else in the system
    // would explain that.
    $subclass = new class extends AppDeclAnnotatedBaseHandler {};

    expect(fn () => declApp()->withCredentialHandler($subclass, [DECL_TEST_KEY])->build())
        ->toThrow(ConfigException::class, 'does not inherit class attributes');
});

it('refuses a credential field key the core would reject at first connect', function () {
    $camelCase = new
    #[CredentialSchema(title: 'ERP')]
    #[CredentialField(key: 'userName')]
    class implements UserCredentialHandler {
        /** @param array<string, string> $credential */
        public function validate(CredentialContext $ctx, array $credential): CredentialValidation
        {
            return CredentialValidation::ok();
        }

        /** @param array<string, string> $credential */
        public function revoke(CredentialContext $ctx, array $credential): void {}
    };

    // The core validates key against ^[a-z][a-z0-9_]*$ when the connector
    // registers; catching it here turns a deploy-later rejection in someone
    // else's log into a startup failure in this one.
    expect(fn () => declApp()->withCredentialHandler($camelCase, [DECL_TEST_KEY])->build())
        ->toThrow(ConfigException::class, "key 'userName'");
});

it('refuses #[CredentialField]s with no #[CredentialSchema]', function () {
    $handler = new
    #[CredentialField(key: 'username')]
    class implements UserCredentialHandler {
        /** @param array<string, string> $credential */
        public function validate(CredentialContext $ctx, array $credential): CredentialValidation
        {
            return CredentialValidation::ok();
        }

        /** @param array<string, string> $credential */
        public function revoke(CredentialContext $ctx, array $credential): void {}
    };

    expect(fn () => declApp()->withCredentialHandler($handler, [DECL_TEST_KEY])->build())
        ->toThrow(ConfigException::class, '#[CredentialSchema]');
});

it('refuses a credential schema with no fields — the platform would render an empty form', function () {
    $handler = new
    #[CredentialSchema(title: 'ERP')]
    class implements UserCredentialHandler {
        /** @param array<string, string> $credential */
        public function validate(CredentialContext $ctx, array $credential): CredentialValidation
        {
            return CredentialValidation::ok();
        }

        /** @param array<string, string> $credential */
        public function revoke(CredentialContext $ctx, array $credential): void {}
    };

    expect(fn () => declApp()->withCredentialHandler($handler, [DECL_TEST_KEY])->build())
        ->toThrow(ConfigException::class, 'empty form');
});

it('refuses a credential schema with no title, an unknown kind, a duplicate key, an unknown type, or an optionless select', function () {
    $noTitle = new
    #[CredentialSchema(kind: 'basic')]
    #[CredentialField(key: 'u')]
    class implements UserCredentialHandler {
        /** @param array<string, string> $credential */
        public function validate(CredentialContext $ctx, array $credential): CredentialValidation
        {
            return CredentialValidation::ok();
        }

        /** @param array<string, string> $credential */
        public function revoke(CredentialContext $ctx, array $credential): void {}
    };

    $badKind = new
    #[CredentialSchema(kind: 'oauth2', title: 'ERP')]
    #[CredentialField(key: 'u')]
    class implements UserCredentialHandler {
        /** @param array<string, string> $credential */
        public function validate(CredentialContext $ctx, array $credential): CredentialValidation
        {
            return CredentialValidation::ok();
        }

        /** @param array<string, string> $credential */
        public function revoke(CredentialContext $ctx, array $credential): void {}
    };

    $dupKey = new
    #[CredentialSchema(title: 'ERP')]
    #[CredentialField(key: 'u')]
    #[CredentialField(key: 'u', label: 'again')]
    class implements UserCredentialHandler {
        /** @param array<string, string> $credential */
        public function validate(CredentialContext $ctx, array $credential): CredentialValidation
        {
            return CredentialValidation::ok();
        }

        /** @param array<string, string> $credential */
        public function revoke(CredentialContext $ctx, array $credential): void {}
    };

    $badType = new
    #[CredentialSchema(title: 'ERP')]
    #[CredentialField(key: 'u', type: 'textarea')]
    class implements UserCredentialHandler {
        /** @param array<string, string> $credential */
        public function validate(CredentialContext $ctx, array $credential): CredentialValidation
        {
            return CredentialValidation::ok();
        }

        /** @param array<string, string> $credential */
        public function revoke(CredentialContext $ctx, array $credential): void {}
    };

    $emptySelect = new
    #[CredentialSchema(title: 'ERP')]
    #[CredentialField(key: 'company', type: 'select')]
    class implements UserCredentialHandler {
        /** @param array<string, string> $credential */
        public function validate(CredentialContext $ctx, array $credential): CredentialValidation
        {
            return CredentialValidation::ok();
        }

        /** @param array<string, string> $credential */
        public function revoke(CredentialContext $ctx, array $credential): void {}
    };

    expect(fn () => declApp()->withCredentialHandler($noTitle, [DECL_TEST_KEY])->build())
        ->toThrow(ConfigException::class, 'no title');
    expect(fn () => declApp()->withCredentialHandler($badKind, [DECL_TEST_KEY])->build())
        ->toThrow(ConfigException::class, "kind 'oauth2'");
    expect(fn () => declApp()->withCredentialHandler($dupKey, [DECL_TEST_KEY])->build())
        ->toThrow(ConfigException::class, "duplicate field key 'u'");
    expect(fn () => declApp()->withCredentialHandler($badType, [DECL_TEST_KEY])->build())
        ->toThrow(ConfigException::class, "type 'textarea'");
    expect(fn () => declApp()->withCredentialHandler($emptySelect, [DECL_TEST_KEY])->build())
        ->toThrow(ConfigException::class, 'no options');
});

it('refuses a relational provider that carries no #[RelationalSource]', function () {
    // Registering an unannotated provider would keep the provider and declare
    // nothing — extraction silently never happens, which is the failure class
    // this layer exists to close.
    expect(fn () => declApp()->withRelationalSchemaProvider(declBareProvider())->build())
        ->toThrow(ConfigException::class, '#[RelationalSource]');
});

it('names the blank field when a relational source leaves one empty', function () {
    $blankEngine = new
    #[RelationalSource(engine: '', describeTool: 'erp.describe_schema', queryTool: 'erp.query_sql', sqlArg: 'sql')]
    class implements RelationalSchemaProvider {
        public function scopes(): array
        {
            return [];
        }

        public function describe(string $scopeKey): CanonicalSchema
        {
            return new CanonicalSchema(entities: [], relations: []);
        }

        public function catalogFingerprint(): string
        {
            return '';
        }
    };

    $blankSqlArg = new
    #[RelationalSource(engine: 'mysql', describeTool: 'erp.describe_schema', queryTool: 'erp.query_sql', sqlArg: '  ')]
    class implements RelationalSchemaProvider {
        public function scopes(): array
        {
            return [];
        }

        public function describe(string $scopeKey): CanonicalSchema
        {
            return new CanonicalSchema(entities: [], relations: []);
        }

        public function catalogFingerprint(): string
        {
            return '';
        }
    };

    expect(fn () => declApp()->withRelationalSchemaProvider($blankEngine)->build())
        ->toThrow(ConfigException::class, 'declares no engine');
    expect(fn () => declApp()->withRelationalSchemaProvider($blankSqlArg)->build())
        ->toThrow(ConfigException::class, 'declares no sqlArg');
});

// ---------------------------------------------------------------------------
// The cross-check: a relational source must name tools and an argument that
// this connector actually has. Nothing downstream catches these — the core
// validates relational_source for non-emptiness and namespace only.
// ---------------------------------------------------------------------------

it('refuses a relational source naming tools this connector does not declare', function () {
    $typoedDescribe = new
    #[RelationalSource(engine: 'mysql', describeTool: 'erp.describe_schemas', queryTool: 'erp.query_sql', sqlArg: 'sql')]
    class implements RelationalSchemaProvider {
        public function scopes(): array
        {
            return [];
        }

        public function describe(string $scopeKey): CanonicalSchema
        {
            return new CanonicalSchema(entities: [], relations: []);
        }

        public function catalogFingerprint(): string
        {
            return '';
        }
    };

    $typoedQuery = new
    #[RelationalSource(engine: 'mysql', describeTool: 'erp.describe_schema', queryTool: 'erp.query_sqll', sqlArg: 'sql')]
    class implements RelationalSchemaProvider {
        public function scopes(): array
        {
            return [];
        }

        public function describe(string $scopeKey): CanonicalSchema
        {
            return new CanonicalSchema(entities: [], relations: []);
        }

        public function catalogFingerprint(): string
        {
            return '';
        }
    };

    // A typo'd query tool would otherwise register fine, and the core's gate
    // would then govern a key nothing answers to while the real tool runs
    // ungoverned. The message lists what IS declared, so the typo is visible.
    expect(fn () => declApp()->withRelationalSchemaProvider($typoedDescribe)->build())
        ->toThrow(ConfigException::class, "describeTool 'erp.describe_schemas'");
    expect(fn () => declApp()->withRelationalSchemaProvider($typoedQuery)->build())
        ->toThrow(ConfigException::class, 'declared tools: erp.describe_schema, erp.query_sql');
});

it('refuses a sqlArg that does not match the query tool input schema exactly, including case', function () {
    // The .NET SDK serializes PascalCase, so "Sql" is right there and wrong
    // here. A wrong-cased name reads null at gate time: the gate authorizes an
    // empty string and the real SQL is never seen.
    $wrongCase = new
    #[RelationalSource(engine: 'mysql', describeTool: 'erp.describe_schema', queryTool: 'erp.query_sql', sqlArg: 'Sql')]
    class implements RelationalSchemaProvider {
        public function scopes(): array
        {
            return [];
        }

        public function describe(string $scopeKey): CanonicalSchema
        {
            return new CanonicalSchema(entities: [], relations: []);
        }

        public function catalogFingerprint(): string
        {
            return '';
        }
    };

    // PRECONDITION, asserted rather than assumed: the tool really does take
    // 'sql' and really does not take 'Sql'. Without this the test could pass
    // for the wrong reason (e.g. no properties at all).
    $tools = declApp()->build()->agents()->declarations()[0]['tools'];
    $querySchema = $tools[1]['input_schema_json'];
    expect(array_keys($querySchema['properties']))->toBe(['sql']);

    expect(fn () => declApp()->withRelationalSchemaProvider($wrongCase)->build())
        ->toThrow(ConfigException::class, "sqlArg 'Sql'");
});

it('derives paramsArg into the relational source declaration as params_arg', function () {
    $provider = new
    #[RelationalSource(
        engine: 'mysql', describeTool: 'erp.describe_schema', queryTool: 'erp.query_sql',
        sqlArg: 'sql', paramsArg: 'params',
    )]
    class implements RelationalSchemaProvider {
        public function scopes(): array
        {
            return [];
        }

        public function describe(string $scopeKey): CanonicalSchema
        {
            return new CanonicalSchema(entities: [], relations: []);
        }

        public function catalogFingerprint(): string
        {
            return '';
        }
    };

    $app = declAppWithParamsArg()->withRelationalSchemaProvider($provider)->build();

    expect($app->relationalSourceDeclaration()['params_arg'] ?? null)->toBe('params');

    // ⚠ ONE HOP FURTHER, and the hop that actually matters. The assertion above
    // reads the in-memory declaration; the platform only ever sees what
    // buildRegister() puts on the wire. A missing setParamsArg() there would let
    // a connector declare paramsArg, pass every bootstrap check, and still
    // register as "accepts no parameters" — the platform would never send the
    // argument and the query would run UNFILTERED while every layer reported
    // success. That is exactly the bug this test was added to catch.
    $register = \Vested\Connect\Sdk\Hub\StreamHandler::buildRegister($app)->getRegister();
    expect($register)->not->toBeNull();

    $wire = $register?->getRelationalSource();
    expect($wire)->not->toBeNull()
        ->and($wire?->getParamsArg())->toBe('params')
        ->and($wire?->getSqlArg())->toBe('sql');
});

it('refuses a paramsArg naming no argument of the query tool, and names the tool and its arguments', function () {
    // paramsArg fails differently from sqlArg but just as quietly: name it
    // wrong and the connector never receives the parameters, so a filter
    // silently does not apply and a dashboard shows unfiltered numbers that
    // look plausible. Same fix — refuse at bootstrap, where a human is
    // watching.
    $provider = new
    #[RelationalSource(
        engine: 'mysql', describeTool: 'erp.describe_schema', queryTool: 'erp.query_sql',
        sqlArg: 'sql', paramsArg: 'bindings',
    )]
    class implements RelationalSchemaProvider {
        public function scopes(): array
        {
            return [];
        }

        public function describe(string $scopeKey): CanonicalSchema
        {
            return new CanonicalSchema(entities: [], relations: []);
        }

        public function catalogFingerprint(): string
        {
            return '';
        }
    };

    // declApp() (not declAppWithParamsArg()): the query tool's only argument
    // is 'sql', so 'bindings' names nothing — the case this check exists for.
    expect(fn () => declApp()->withRelationalSchemaProvider($provider)->build())
        ->toThrow(
            ConfigException::class,
            "relational source declares paramsArg 'bindings' but tool 'erp.query_sql' has no such "
            . 'argument (its arguments are: sql). '
        );
});

it('resolves the query tool arguments through a root $ref, rather than rejecting a valid connector', function () {
    $provider = new
    #[RelationalSource(engine: 'mysql', describeTool: 'ref.describe', queryTool: 'ref.query', sqlArg: 'sql')]
    class implements RelationalSchemaProvider {
        public function scopes(): array
        {
            return [];
        }

        public function describe(string $scopeKey): CanonicalSchema
        {
            return new CanonicalSchema(entities: [], relations: []);
        }

        public function catalogFingerprint(): string
        {
            return '';
        }
    };

    $app = ConnectorApp::create()
        ->agent('ref')
            ->withTool(
                key: 'ref.describe', name: 'Describe', description: '',
                inputSchema: ['type' => 'object'], outputSchema: ['type' => 'object'],
                handler: fn (array $a, ToolContext $c) => [],
            )
            ->withTool(
                key: 'ref.query', name: 'Query', description: '',
                inputSchema: [
                    '$ref'        => '#/definitions/QueryArgs',
                    'definitions' => [
                        'QueryArgs' => ['type' => 'object', 'properties' => ['sql' => ['type' => 'string']]],
                    ],
                ],
                outputSchema: ['type' => 'object'],
                handler: fn (array $a, ToolContext $c) => [],
            )
        ->endAgent()
        ->withRelationalSchemaProvider($provider)
        ->build();

    expect($app->relationalSourceDeclaration()['sql_arg'] ?? null)->toBe('sql');
});
