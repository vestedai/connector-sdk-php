<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Hub;

use Vested\Connect\Sdk\ConnectorApp;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectorMsg;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\DeclIssue;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\RegisterAck;
use Vested\Connect\Sdk\Hub\StreamHandler;
use Vested\Connect\Sdk\Tool\ToolContext;

it('builds a Hello frame with sdk language/version/worker_id', function () {
    $msg = StreamHandler::buildHello(sdkLanguage: 'php', sdkVersion: '1.0.0', workerId: 'host:123');
    expect($msg)->toBeInstanceOf(ConnectorMsg::class);
    $hello = $msg->getHello();
    expect($hello)->not->toBeNull();
    assert($hello !== null);
    expect($hello->getSdkLanguage())->toBe('php');
    expect($hello->getSdkVersion())->toBe('1.0.0');
    expect($hello->getWorkerId())->toBe('host:123');
});

it('builds a Register frame from a ConnectorApp', function () {
    $app = ConnectorApp::create()
        ->agent('x.y')
            ->withInstruction('Be helpful.', position: 0)
            ->withTool(
                key: 'x.y.t', name: 'T', description: '',
                inputSchema: ['type' => 'object'], outputSchema: ['type' => 'object'],
                handler: fn (array $a, ToolContext $c) => ['ok' => true],
            )
        ->endAgent()
        ->build();

    $msg = StreamHandler::buildRegister($app);
    $reg = $msg->getRegister();
    expect($reg)->not->toBeNull();
    assert($reg !== null);
    expect($reg->getBaselineFingerprint())->toBe($app->agents()->fingerprint());
    expect($reg->getAgents())->toHaveCount(1);
    $agent = $reg->getAgents()[0];
    expect($agent->getKey())->toBe('x.y');
    expect($agent->getInstructions())->toHaveCount(1);
    expect($agent->getTools())->toHaveCount(1);
    expect($agent->getTools()[0]->getInputSchemaJson())->toBe('{"type":"object"}');
});

it('builds a Heartbeat frame', function () {
    $msg = StreamHandler::buildHeartbeat();
    $hb = $msg->getHeartbeat();
    expect($hb)->not->toBeNull();
    assert($hb !== null);
    expect($hb->getAt())->not->toBeNull();
});

it('formats RegisterAck issues into human-readable lines', function () {
    $ack = new RegisterAck();
    $ack->setStatus('rejected');
    $ack->setIssues([
        new DeclIssue(['path' => 'agents[0].key', 'code' => 'namespace_violation', 'message' => 'bad']),
        new DeclIssue(['path' => 'agents[0].tools[0]', 'code' => 'schema_invalid', 'message' => 'bad schema']),
    ]);
    $lines = StreamHandler::formatRegisterIssues($ack);
    expect($lines)->toHaveCount(2);
    expect($lines[0])->toContain('namespace_violation');
    expect($lines[0])->toContain('agents[0].key');
});
