<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Connection;

use Vested\Connect\Sdk\Agent\AgentBuilder;
use Vested\Connect\Sdk\Agent\AgentRegistry;
use Vested\Connect\Sdk\Attribute\ConnectionField;
use Vested\Connect\Sdk\Attribute\ConnectionProfile;
use Vested\Connect\Sdk\Attribute\ConnectionResourceField;
use Vested\Connect\Sdk\Connection\ConnectionOpContext;
use Vested\Connect\Sdk\Connection\ConnectionProfileHandler;
use Vested\Connect\Sdk\Connection\ConnectionResource;
use Vested\Connect\Sdk\ConnectorApp;
use Vested\Connect\Sdk\Credential\CredentialValidation;
use Vested\Connect\Sdk\Exception\ConfigException;
use Vested\Connect\Sdk\Scanner\DeclarationFactory;
use Vested\Connect\Sdk\Tool\ToolContext;

const CONN_TEST_KEY = '-----BEGIN PRIVATE KEY-----connection-test-----END PRIVATE KEY-----';

function googleProfile(): ConnectionProfileHandler
{
    return new
    #[ConnectionProfile(key: 'google', title: 'Google account', authKind: 'oauth2', oauthProvider: 'google',
        scopes: ['analytics.readonly', 'adwords'], resourceDiscoverable: true, resourceTitle: 'Property')]
    class implements ConnectionProfileHandler {
        public function validate(ConnectionOpContext $ctx, array $credential): CredentialValidation { return CredentialValidation::ok(); }
        public function listResources(ConnectionOpContext $ctx, array $credential): array { return [new ConnectionResource('properties/1', 'Main')]; }
    };
}

function moengageProfile(): ConnectionProfileHandler
{
    return new
    #[ConnectionProfile(key: 'moengage', title: 'MoEngage workspace', authKind: 'fields', helpText: 'From the dashboard.')]
    #[ConnectionField(key: 'workspace_id', label: 'Workspace ID')]
    #[ConnectionField(key: 'data_api_key', label: 'Data API key', type: 'password')]
    #[ConnectionField(key: 'service_account', label: 'Service account JSON', type: 'textarea', required: false)]
    class implements ConnectionProfileHandler {
        public function validate(ConnectionOpContext $ctx, array $credential): CredentialValidation { return CredentialValidation::ok(); }
        public function listResources(ConnectionOpContext $ctx, array $credential): array { return []; }
    };
}

function ga4Builder(): AgentBuilder
{
    return (new AgentBuilder('mkt.ga4'))
        ->name('GA4')->description('Website analytics')->withModel('openai', 'gpt-4o')
        ->withInstruction('You analyse GA4.')
        ->withTool(key: 'mkt.ga4.report', name: 'Report', description: 'A report',
            inputSchema: ['type' => 'object'], outputSchema: ['type' => 'object'],
            handler: fn (array $a, ToolContext $c) => []);
}

it('derives the oauth2 and fields profile declarations the platform validates', function () {
    expect(DeclarationFactory::connectionProfileFrom(googleProfile()))->toBe([
        'key' => 'google', 'title' => 'Google account', 'help_text' => '', 'auth_kind' => 'oauth2',
        'oauth_provider' => 'google', 'scopes' => ['analytics.readonly', 'adwords'], 'fields' => [],
        'resource' => ['discoverable' => true, 'title' => 'Property', 'fields' => []],
    ]);

    $moe = DeclarationFactory::connectionProfileFrom(moengageProfile());
    expect($moe['auth_kind'])->toBe('fields')
        ->and($moe['oauth_provider'])->toBe('')
        ->and($moe['scopes'])->toBe([])
        ->and($moe['resource'])->toBeNull()
        ->and(array_column($moe['fields'], 'type'))->toBe(['text', 'password', 'textarea']);
});

it('refuses a profile the platform would reject, at start-up', function (object $handler, string $message) {
    expect(fn () => DeclarationFactory::connectionProfileFrom($handler))->toThrow(ConfigException::class, $message);
})->with([
    'no attribute' => [new class implements ConnectionProfileHandler {
        public function validate(ConnectionOpContext $c, array $x): CredentialValidation { return CredentialValidation::ok(); }
        public function listResources(ConnectionOpContext $c, array $x): array { return []; }
    }, '#[ConnectionProfile]'],
    'bad auth kind' => [new #[ConnectionProfile(key: 'x', title: 'X', authKind: 'saml')] class implements ConnectionProfileHandler {
        public function validate(ConnectionOpContext $c, array $x): CredentialValidation { return CredentialValidation::ok(); }
        public function listResources(ConnectionOpContext $c, array $x): array { return []; }
    }, 'authKind'],
    'oauth2 without scopes' => [new #[ConnectionProfile(key: 'x', title: 'X', authKind: 'oauth2', oauthProvider: 'google')] class implements ConnectionProfileHandler {
        public function validate(ConnectionOpContext $c, array $x): CredentialValidation { return CredentialValidation::ok(); }
        public function listResources(ConnectionOpContext $c, array $x): array { return []; }
    }, 'scope'],
    'oauth2 with fields' => [new #[ConnectionProfile(key: 'x', title: 'X', authKind: 'oauth2', oauthProvider: 'google', scopes: ['a'])] #[ConnectionField(key: 'k')] class implements ConnectionProfileHandler {
        public function validate(ConnectionOpContext $c, array $x): CredentialValidation { return CredentialValidation::ok(); }
        public function listResources(ConnectionOpContext $c, array $x): array { return []; }
    }, 'collects no fields'],
    'fields without fields' => [new #[ConnectionProfile(key: 'x', title: 'X', authKind: 'fields')] class implements ConnectionProfileHandler {
        public function validate(ConnectionOpContext $c, array $x): CredentialValidation { return CredentialValidation::ok(); }
        public function listResources(ConnectionOpContext $c, array $x): array { return []; }
    }, '#[ConnectionField]'],
    'secret resource field' => [new #[ConnectionProfile(key: 'x', title: 'X', authKind: 'oauth2', oauthProvider: 'google', scopes: ['a'])] #[ConnectionResourceField(key: 'token', type: 'password')] class implements ConnectionProfileHandler {
        public function validate(ConnectionOpContext $c, array $x): CredentialValidation { return CredentialValidation::ok(); }
        public function listResources(ConnectionOpContext $c, array $x): array { return []; }
    }, 'password'],
]);

it('keeps the fingerprint of an agent with no requirements byte-identical to 0.11.0', function () {
    $registry = new AgentRegistry([ga4Builder()]);

    expect($registry->declarations()[0])->not->toHaveKey('connection_requirements')
        ->and($registry->fingerprint())->toBe('7913f092a2e6bae934b27a027dee557fa812344fa4680690971f35a5acdf312d');
});

it('puts requirements inside the fingerprint', function () {
    $plain = (new AgentRegistry([ga4Builder()]))->fingerprint();
    $required = new AgentRegistry([ga4Builder()->requiresConnection('google', ['analytics.readonly'])]);

    expect($required->fingerprint())->not->toBe($plain)
        ->and($required->declarations()[0]['connection_requirements'])->toBe([
            ['profile' => 'google', 'scopes' => ['analytics.readonly'], 'cardinality' => 'one', 'label_hint' => ''],
        ]);
});

it('refuses a malformed requirement on the builder', function () {
    expect(fn () => ga4Builder()->requiresConnection('moengage', [], 'many'))->toThrow(ConfigException::class, 'labelHint')
        ->and(fn () => ga4Builder()->requiresConnection('google', [], 'some'))->toThrow(ConfigException::class, 'cardinality')
        ->and(fn () => ga4Builder()->requiresConnection('google')->requiresConnection('google'))->toThrow(ConfigException::class, 'already');
});

it('reads #[RequiresConnection] off a scanned agent class', function () {
    $app = ConnectorApp::create()
        ->scanNamespace('Vested\\Connect\\Sdk\\Tests\\Fixtures\\ConnectionAgents', __DIR__.'/../../Fixtures/ConnectionAgents')
        ->build();

    expect($app->agents()->declarations()[0]['connection_requirements'][0]['profile'])->toBe('google');
});

it('registers profiles, shares the credential key ring, and checks requirements against them', function () {
    $app = ConnectorApp::create();
    $app->agent('mkt.ga4')->withModel('openai', 'gpt-4o')->withInstruction('x')
        ->requiresConnection('google', ['analytics.readonly'])
        ->withTool(key: 'mkt.ga4.report', name: 'R', description: '', inputSchema: ['type' => 'object'],
            outputSchema: ['type' => 'object'], handler: fn (array $a, ToolContext $c) => [])
        ->endAgent();
    $app->build();

    expect(fn () => $app->assertConnectionRequirementsDeclared())->toThrow(ConfigException::class, "'google'");

    $app->withConnectionProfile(googleProfile(), [CONN_TEST_KEY])->withConnectionProfile(moengageProfile());

    $app->assertConnectionRequirementsDeclared();
    expect($app->hasConnectionProfiles())->toBeTrue()
        ->and(array_column($app->connectionProfileDeclarations(), 'key'))->toBe(['google', 'moengage'])
        ->and($app->connectionProfileHandler('moengage'))->not->toBeNull()
        ->and($app->credentialPrivateKeys())->toBe([CONN_TEST_KEY])
        ->and(fn () => $app->withConnectionProfile(googleProfile()))->toThrow(ConfigException::class, 'already');
});

it('refuses a requirement scope its profile never declared', function () {
    $app = ConnectorApp::create();
    $app->agent('mkt.ads')->withModel('openai', 'gpt-4o')->withInstruction('x')
        ->requiresConnection('google', ['content'])
        ->withTool(key: 'mkt.ads.t', name: 'T', description: '', inputSchema: ['type' => 'object'],
            outputSchema: ['type' => 'object'], handler: fn (array $a, ToolContext $c) => [])
        ->endAgent();
    $app->build()->withConnectionProfile(googleProfile(), [CONN_TEST_KEY]);

    $app->assertConnectionRequirementsDeclared();
})->throws(ConfigException::class, "'content'");

it('refuses a connection profile with no private key anywhere', function () {
    putenv('VESTED_CREDENTIAL_PRIVATE_KEY');
    putenv('VESTED_CREDENTIAL_PRIVATE_KEY_FILE');

    ConnectorApp::create()->withConnectionProfile(googleProfile());
})->throws(ConfigException::class, 'VESTED_CREDENTIAL_PRIVATE_KEY');
