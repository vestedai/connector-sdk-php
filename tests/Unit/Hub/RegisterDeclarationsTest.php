<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Hub;

// ---------------------------------------------------------------------------
// Task 5 acceptance: both Register declarations must survive SERIALIZATION.
//
// StreamHandlerTest inspects $msg->getRegister() in memory, which proves only
// that a setter was called — the same message could carry a field the hub can
// never read (wrong number, wrong type) and that test would still pass. Every
// assertion below is made against a message rebuilt with mergeFromString()
// from the bytes serializeToString() produced, i.e. a DIFFERENT object graph
// reconstructed from the wire representation. Same mechanism Task 4's
// RelationalSourceDeclTest used to prove the vendored proto carries the field.
//
// Its limits are real and stated in task-5-report.md: this is a serialization
// assertion in one process, not a live socket (the .NET side has the socket;
// PHP's Integration suite is INTEGRATION=1 gated and skips by default).
// ---------------------------------------------------------------------------

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Vested\Connect\Sdk\Attribute\CredentialField;
use Vested\Connect\Sdk\Attribute\CredentialSchema;
use Vested\Connect\Sdk\Attribute\RelationalSource;
use Vested\Connect\Sdk\ConnectorApp;
use Vested\Connect\Sdk\Credential\CredentialContext;
use Vested\Connect\Sdk\Credential\CredentialValidation;
use Vested\Connect\Sdk\Credential\UserCredentialHandler;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectorMsg;
use Vested\Connect\Sdk\Hub\StreamHandler;
use Vested\Connect\Sdk\Schema\CanonicalSchema;
use Vested\Connect\Sdk\Schema\RelationalSchemaProvider;
use Vested\Connect\Sdk\Tool\ToolContext;

/** PSR-3 sink that keeps what it was told, so a warning can be asserted on. */
final class HubDeclRecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $messages = [];

    /** @param array<string, mixed> $context */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }

    public function joined(): string
    {
        return implode("\n", $this->messages);
    }
}

function hubDeclCredentialHandler(): UserCredentialHandler
{
    return new
    #[CredentialSchema(kind: 'basic', title: 'Al-Saif ERP account', helpText: 'Ask IT for a service login.')]
    #[CredentialField(key: 'username', label: 'ERP username', type: 'text', required: true, placeholder: 'you@alsaif')]
    #[CredentialField(key: 'password', label: 'ERP password', type: 'password', required: true)]
    #[CredentialField(key: 'company', label: 'Company', type: 'select', required: false, options: ['KSA', 'UAE'])]
    class implements UserCredentialHandler {
        /** @param array<string, string> $credential */
        public function validate(CredentialContext $ctx, array $credential): CredentialValidation
        {
            return CredentialValidation::ok();
        }

        /** @param array<string, string> $credential */
        public function revoke(CredentialContext $ctx, array $credential): void {}
    };
}

/** @param \Closure(): string $fingerprint */
function hubDeclSchemaProvider(\Closure $fingerprint): RelationalSchemaProvider
{
    return new
    #[RelationalSource(
        engine: 'sqlserver',
        describeTool: 'erp.describe_schema',
        queryTool: 'erp.query_sql',
        sqlArg: 'sql',
    )]
    class($fingerprint) implements RelationalSchemaProvider {
        /** @param \Closure(): string $fingerprint */
        public function __construct(private readonly \Closure $fingerprint) {}

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
            return ($this->fingerprint)();
        }
    };
}

/** A connector that declares BOTH: per-user credentials and a relational source. */
function hubDeclApp(
    ?RelationalSchemaProvider $provider = null,
    ?LoggerInterface $logger = null,
): ConnectorApp {
    return ConnectorApp::create()
        ->withLogger($logger ?? new NullLogger())
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
        ->withCredentialHandler(hubDeclCredentialHandler(), ['-----BEGIN PRIVATE KEY-----test-----END PRIVATE KEY-----'])
        ->withRelationalSchemaProvider($provider ?? hubDeclSchemaProvider(fn () => 'catalog-hash-9f8e7d'))
        ->build();
}

/** Serialize the built Register and parse the BYTES back into a fresh message. */
function hubDeclRoundTrip(ConnectorApp $app): \Vested\Connect\Sdk\Generated\Proto\Vested\V1\Register
{
    $bytes = StreamHandler::buildRegister($app)->serializeToString();

    $parsed = new ConnectorMsg();
    $parsed->mergeFromString($bytes);

    $reg = $parsed->getRegister();
    expect($reg)->not->toBeNull();
    assert($reg !== null);

    return $reg;
}

it('carries BOTH declarations on one Register through a serialize/parse round trip', function () {
    $reg = hubDeclRoundTrip(hubDeclApp());

    // ---- credential_schema (field 3) ----
    expect($reg->hasCredentialSchema())->toBeTrue();
    $cred = $reg->getCredentialSchema();
    assert($cred !== null);
    expect($cred->getKind())->toBe('basic');
    expect($cred->getTitle())->toBe('Al-Saif ERP account');
    expect($cred->getHelpText())->toBe('Ask IT for a service login.');

    $fields = iterator_to_array($cred->getFields());
    expect($fields)->toHaveCount(3);

    expect($fields[0]->getKey())->toBe('username');
    expect($fields[0]->getLabel())->toBe('ERP username');
    expect($fields[0]->getType())->toBe('text');
    expect($fields[0]->getRequired())->toBeTrue();
    expect($fields[0]->getPlaceholder())->toBe('you@alsaif');

    expect($fields[1]->getKey())->toBe('password');
    expect($fields[1]->getType())->toBe('password');

    expect($fields[2]->getKey())->toBe('company');
    expect($fields[2]->getType())->toBe('select');
    expect($fields[2]->getRequired())->toBeFalse();
    expect(iterator_to_array($fields[2]->getOptions()))->toBe(['KSA', 'UAE']);

    // ---- relational_source (field 4) ----
    expect($reg->hasRelationalSource())->toBeTrue();
    $rel = $reg->getRelationalSource();
    assert($rel !== null);
    expect($rel->getEngine())->toBe('sqlserver');
    expect($rel->getDescribeTool())->toBe('erp.describe_schema');
    expect($rel->getQueryTool())->toBe('erp.query_sql');
    expect($rel->getSqlArg())->toBe('sql');
    expect($rel->getFingerprint())->toBe('catalog-hash-9f8e7d');

    // ---- and the pre-existing half is still on the SAME message ----
    expect($reg->getAgents())->toHaveCount(1);
    expect($reg->getAgents()[0]->getTools())->toHaveCount(2);
    expect($reg->getBaselineFingerprint())->not->toBe('');
});

it('leaves both declarations absent for a connector that declares neither', function () {
    // "Declare nothing, stay untouched": absence is what tells the platform to
    // hide the connector from the credential UI and never extract its schema.
    $app = ConnectorApp::create()
        ->agent('plain')
            ->withTool(
                key: 'plain.get', name: 'Get', description: '',
                inputSchema: ['type' => 'object'], outputSchema: ['type' => 'object'],
                handler: fn (array $a, ToolContext $c) => [],
            )
        ->endAgent()
        ->build();

    $reg = hubDeclRoundTrip($app);

    expect($reg->hasCredentialSchema())->toBeFalse();
    expect($reg->hasRelationalSource())->toBeFalse();
    expect($reg->getAgents())->toHaveCount(1);
});

it('reads the catalog fingerprint LIVE on every Register, never once at declaration time', function () {
    // A fingerprint captured when the provider was registered is stale by
    // definition: the catalog it describes changes underneath it, and a stale
    // value tells the core "nothing changed, do not re-extract".
    $calls = 0;
    $app = hubDeclApp(hubDeclSchemaProvider(function () use (&$calls) {
        $calls++;

        return "catalog-{$calls}";
    }));

    expect(hubDeclRoundTrip($app)->getRelationalSource()?->getFingerprint())->toBe('catalog-1');
    expect(hubDeclRoundTrip($app)->getRelationalSource()?->getFingerprint())->toBe('catalog-2');
});

it('still declares the relational source when the fingerprint throws, with an empty fingerprint', function () {
    // The whole point: omitting the declaration would silently disable schema
    // extraction and the SQL gate. An empty fingerprint makes the core
    // re-extract — expensive, but correct and visible.
    $logger = new HubDeclRecordingLogger();
    $app = hubDeclApp(
        hubDeclSchemaProvider(fn () => throw new \RuntimeException('SQLSTATE[08006] could not connect')),
        $logger,
    );

    $rel = hubDeclRoundTrip($app)->getRelationalSource();
    assert($rel !== null);

    expect($rel->getEngine())->toBe('sqlserver');
    expect($rel->getQueryTool())->toBe('erp.query_sql');
    expect($rel->getFingerprint())->toBe('');

    $logged = $logger->joined();
    expect($logged)->toContain('catalog fingerprint unavailable');
    expect($logged)->toContain('erp.query_sql');
    expect($logged)->toContain('RuntimeException');
    expect($logged)->not->toContain('did not answer');
});
