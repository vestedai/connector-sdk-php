<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Swoole;

use Psr\Log\NullLogger;
use Vested\Connect\Sdk\ConnectorApp;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectorMsg;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\HelloAck;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\HubMsg;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\RegisterAck;
use Vested\Connect\Sdk\Swoole\Daemon;
use Vested\Connect\Sdk\Tool\ToolContext;

/**
 * Test stub for GrpcClient. We can't extend final, so we duck-type via
 * a class with the same public surface and pass to Daemon's constructor.
 */
function buildFakeGrpcClient(array $scriptedInbound): object
{
    return new class($scriptedInbound) {
        /** @var list<HubMsg> $inbound */
        public array $inbound;
        public array $outbound = [];
        public bool $opened = false;
        public bool $closed = false;
        public function __construct(array $scripted) { $this->inbound = $scripted; }
        public function open(): void { $this->opened = true; }
        public function send(ConnectorMsg $msg): void { $this->outbound[] = $msg; }
        public function recv(float $timeoutSeconds = 30.0): ?HubMsg {
            return array_shift($this->inbound);
        }
        public function close(): void { $this->closed = true; }
    };
}

it('completes the handshake then exits on EOF', function () {
    \Swoole\Coroutine\run(function () {
        $helloAck = new HubMsg();
        $helloAck->setHelloAck(new HelloAck());
        $regAck = new HubMsg();
        $regAck->setRegisterAck((new RegisterAck())->setStatus('accepted'));

        $fakeClient = buildFakeGrpcClient([$helloAck, $regAck, null /* EOF */]);

        $app = ConnectorApp::create()
            ->withLogger(new NullLogger())
            ->agent('t.x')
                ->withTool(
                    key: 't.x.k', name: 'K', description: '',
                    inputSchema:  ['type' => 'object'],
                    outputSchema: ['type' => 'object'],
                    handler: fn (array $a, ToolContext $c) => [],
                )
            ->endAgent()
            ->build();

        $daemon = new Daemon($app, grpc: $fakeClient, logger: new NullLogger());
        $exit = $daemon->run();
        expect($exit)->toBe(0);
        expect($fakeClient->opened)->toBeTrue();
        expect($fakeClient->closed)->toBeTrue();

        // First two outbound: Hello + Register
        expect($fakeClient->outbound[0]->getBody())->toBe('hello');
        expect($fakeClient->outbound[1]->getBody())->toBe('register');
    });
})->skip(! extension_loaded('swoole'), 'Swoole not installed');

it('register rejected → returns non-zero exit', function () {
    \Swoole\Coroutine\run(function () {
        $helloAck = new HubMsg();
        $helloAck->setHelloAck(new HelloAck());
        $regAck = new HubMsg();
        $regAck->setRegisterAck((new RegisterAck())->setStatus('rejected'));

        $fakeClient = buildFakeGrpcClient([$helloAck, $regAck]);

        $app = ConnectorApp::create()
            ->withLogger(new NullLogger())
            ->agent('x.y')
                ->withTool(
                    key: 'x.y.k', name: 'K', description: '',
                    inputSchema:  ['type' => 'object'],
                    outputSchema: ['type' => 'object'],
                    handler: fn (array $a, ToolContext $c) => [],
                )
            ->endAgent()
            ->build();

        $daemon = new Daemon($app, grpc: $fakeClient, logger: new NullLogger());
        $exit = $daemon->run();
        expect($exit)->toBe(78);  // EX_CONFIG
    });
})->skip(! extension_loaded('swoole'), 'Swoole not installed');
