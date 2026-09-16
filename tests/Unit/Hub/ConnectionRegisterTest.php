<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Hub;

// Asserted against a Register rebuilt from its serialized bytes, like
// RegisterDeclarationsTest: a setter that was called proves nothing about what
// the hub can read.

use Vested\Connect\Sdk\Attribute\ConnectionField;
use Vested\Connect\Sdk\Attribute\ConnectionProfile;
use Vested\Connect\Sdk\Attribute\ConnectionResourceField;
use Vested\Connect\Sdk\Connection\ConnectionOpContext;
use Vested\Connect\Sdk\Connection\ConnectionProfileHandler;
use Vested\Connect\Sdk\ConnectorApp;
use Vested\Connect\Sdk\Credential\CredentialValidation;
use Vested\Connect\Sdk\Exception\ConfigException;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectorMsg;
use Vested\Connect\Sdk\Hub\StreamHandler;
use Vested\Connect\Sdk\Tests\Unit\Support\Decoded;
use Vested\Connect\Sdk\Tool\ToolContext;

const REGISTER_CONN_KEY = '-----BEGIN PRIVATE KEY-----register-----END PRIVATE KEY-----';

function marketingApp(bool $withProfiles): ConnectorApp
{
    $tool = fn (string $key) => [
        'key' => $key, 'name' => 'T', 'description' => '', 'inputSchema' => ['type' => 'object'],
        'outputSchema' => ['type' => 'object'], 'handler' => fn (array $a, ToolContext $c) => [],
    ];

    $app = ConnectorApp::create();
    $app->agent('mkt.ga4')->withModel('openai', 'gpt-4o')->withInstruction('GA4')
        ->requiresConnection('google', ['analytics.readonly'])
        ->withTool(...$tool('mkt.ga4.report'))->endAgent();
    $app->agent('mkt.moengage')->withModel('openai', 'gpt-4o')->withInstruction('MoEngage')
        ->requiresConnection('moengage', [], 'many', 'Market code')
        ->withTool(...$tool('mkt.moengage.campaigns'))->endAgent();
    $app->agent('mkt.plain')->withModel('openai', 'gpt-4o')->withInstruction('Plain')
        ->withTool(...$tool('mkt.plain.ping'))->endAgent();
    $app->build();

    if ($withProfiles) {
        $app->withConnectionProfile(new
            #[ConnectionProfile(key: 'google', title: 'Google account', authKind: 'oauth2', oauthProvider: 'google',
                scopes: ['analytics.readonly'], helpText: 'Links the Google Analytics account used for GA4 reporting.',
                resourceDiscoverable: true, resourceTitle: 'Property')]
            #[ConnectionResourceField(key: 'property_id', label: 'GA4 property ID')]
            class implements ConnectionProfileHandler {
                public function validate(ConnectionOpContext $c, array $x): CredentialValidation { return CredentialValidation::ok(); }
                public function listResources(ConnectionOpContext $c, array $x): array { return []; }
            }, [REGISTER_CONN_KEY]);
        $app->withConnectionProfile(new
            #[ConnectionProfile(key: 'moengage', title: 'MoEngage workspace', authKind: 'fields')]
            #[ConnectionField(key: 'workspace_id', label: 'Workspace ID')]
            #[ConnectionField(key: 'data_api_key', label: 'Data API key', type: 'password')]
            class implements ConnectionProfileHandler {
                public function validate(ConnectionOpContext $c, array $x): CredentialValidation { return CredentialValidation::ok(); }
                public function listResources(ConnectionOpContext $c, array $x): array { return []; }
            });
    }

    return $app;
}

it('carries profiles and requirements across the wire', function () {
    $msg = StreamHandler::buildRegister(marketingApp(withProfiles: true));
    $back = new ConnectorMsg();
    $back->mergeFromString($msg->serializeToString());
    $reg = Decoded::present($back->getRegister(), 'Register');

    $profiles = iterator_to_array($reg->getConnectionProfiles());
    expect(array_map(fn ($p) => $p->getKey(), $profiles))->toBe(['google', 'moengage']);

    $google = $profiles[0];
    $googleResource = Decoded::present($google->getResource(), "the 'google' profile's resource");
    expect($google->getTitle())->toBe('Google account')
        ->and($google->getHelpText())->toBe('Links the Google Analytics account used for GA4 reporting.')
        ->and($google->getAuthKind())->toBe('oauth2')
        ->and($google->getOauthProvider())->toBe('google')
        ->and(iterator_to_array($google->getScopes()))->toBe(['analytics.readonly'])
        ->and($googleResource->getDiscoverable())->toBeTrue()
        ->and($googleResource->getTitle())->toBe('Property')
        ->and(array_map(fn ($f) => $f->getKey(), iterator_to_array($googleResource->getFields())))->toBe(['property_id']);

    $moe = $profiles[1];
    expect($moe->getResource())->toBeNull()
        ->and(array_map(fn ($f) => $f->getType(), iterator_to_array($moe->getFields())))->toBe(['text', 'password']);

    $agents = [];
    foreach ($reg->getAgents() as $a) {
        $agents[$a->getKey()] = $a;
    }
    $ga4Req = $agents['mkt.ga4']->getConnectionRequirements()[0];
    expect($ga4Req->getProfile())->toBe('google')
        ->and($ga4Req->getCardinality())->toBe('one')
        ->and(iterator_to_array($ga4Req->getScopes()))->toBe(['analytics.readonly']);

    $moeReq = $agents['mkt.moengage']->getConnectionRequirements()[0];
    expect($moeReq->getCardinality())->toBe('many')
        ->and($moeReq->getLabelHint())->toBe('Market code')
        ->and(count($agents['mkt.plain']->getConnectionRequirements()))->toBe(0);
});

it('leaves the baseline fingerprint to the agents alone', function () {
    $with = Decoded::present(
        StreamHandler::buildRegister(marketingApp(withProfiles: true))->getRegister(),
        'Register',
    )->getBaselineFingerprint();
    $without = marketingApp(withProfiles: false)->agents()->fingerprint();

    expect($with)->toBe($without);
});

it('refuses to build a Register whose requirements name undeclared profiles', function () {
    StreamHandler::buildRegister(marketingApp(withProfiles: false));
})->throws(ConfigException::class, "'google'");
