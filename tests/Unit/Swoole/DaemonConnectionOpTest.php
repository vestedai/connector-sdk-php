<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Swoole;

use Psr\Log\NullLogger;
use Vested\Connect\Sdk\Attribute\ConnectionProfile;
use Vested\Connect\Sdk\Connection\ConnectionOpContext;
use Vested\Connect\Sdk\Connection\ConnectionProfileHandler;
use Vested\Connect\Sdk\Connection\ConnectionResource;
use Vested\Connect\Sdk\ConnectorApp;
use Vested\Connect\Sdk\Credential\CredentialValidation;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectionOpRequest;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\HelloAck;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\HubMsg;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\RegisterAck;
use Vested\Connect\Sdk\Swoole\Daemon;
use Vested\Connect\Sdk\Tool\ToolContext;

// buildFakeGrpcClient() is defined in DaemonTest.php, in this namespace.

it('answers a connection op on the stream, bound to the connector id from HelloAck', function () {
    \Swoole\Coroutine\run(function () {
        $f = json_decode((string) file_get_contents(__DIR__.'/../../../testdata/platform-connection-envelope-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
        $v = $f['vectors'][0];
        preg_match('/^platform:(\d+)\|org:(\d+)\|connection:(\d+)\|v\d+$/', $v['aad'], $m);
        [, $pc, $org, $conn] = $m;

        $helloAck = new HubMsg();
        $helloAck->setHelloAck((new HelloAck())->setConnectorId($pc));
        $regAck = new HubMsg();
        $regAck->setRegisterAck((new RegisterAck())->setStatus('accepted'));
        $op = new HubMsg();
        $op->setConnectionOpRequest(new ConnectionOpRequest([
            'op_id' => 'op-7', 'op' => 'list_resources', 'organization_id' => $org, 'profile' => 'google',
            'tenant_connection_id' => $conn, 'envelope_json' => (string) json_encode($v['envelope']), 'deadline_ms' => 1000,
        ]));

        $client = buildFakeGrpcClient([$helloAck, $regAck, $op, null]);

        $app = ConnectorApp::create()->withLogger(new NullLogger());
        $app->agent('mkt.ga4')->withModel('openai', 'gpt-4o')->withInstruction('x')
            ->requiresConnection('google', ['analytics.readonly'])
            ->withTool(key: 'mkt.ga4.report', name: 'R', description: '', inputSchema: ['type' => 'object'],
                outputSchema: ['type' => 'object'], handler: fn (array $a, ToolContext $c) => [])
            ->endAgent();
        $app->build()->withConnectionProfile(new
            #[ConnectionProfile(key: 'google', title: 'Google', authKind: 'oauth2', oauthProvider: 'google', scopes: ['analytics.readonly'])]
            class implements ConnectionProfileHandler {
                public function validate(ConnectionOpContext $c, array $x): CredentialValidation { return CredentialValidation::ok(); }
                public function listResources(ConnectionOpContext $c, array $x): array { return [new ConnectionResource('properties/1', 'Main')]; }
            }, [$v['private_key_pem']]);

        (new Daemon($app, grpc: $client, logger: new NullLogger(), drainGraceSeconds: 1))->run();

        $answers = array_values(array_filter($client->outbound, fn ($msg) => $msg->getBody() === 'connection_op_response'));
        expect($answers)->toHaveCount(1);
        $resp = $answers[0]->getConnectionOpResponse();
        expect($resp->getOpId())->toBe('op-7')
            ->and($resp->getOk())->toBeTrue()
            ->and($resp->getResources()[0]->getId())->toBe('properties/1');
    });
})->skip(! extension_loaded('swoole'), 'Swoole not installed');
