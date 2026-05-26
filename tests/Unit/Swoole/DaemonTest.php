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
 *
 * recv() returns scripted HubMsg frames in order. A null entry in the
 * script signals stream close — the fake throws ConnectorException on
 * that recv (matching real GrpcClient's close-detection contract).
 * Entries past the script throw too (so the test never busy-loops).
 */
function buildFakeGrpcClient(array $scriptedInbound): object
{
    return new class($scriptedInbound) {
        /** @var list<HubMsg|null> $inbound */
        public array $inbound;
        public array $outbound = [];
        public bool $opened = false;
        public bool $closed = false;
        public function __construct(array $scripted) { $this->inbound = $scripted; }
        public function open(): void { $this->opened = true; }
        public function send(ConnectorMsg $msg): void { $this->outbound[] = $msg; }
        public function recv(float $timeoutSeconds = 30.0): ?HubMsg {
            if (empty($this->inbound)) {
                throw new \Vested\Connect\Sdk\Exception\ConnectorException('test stream EOF');
            }
            $next = array_shift($this->inbound);
            if ($next === null) {
                throw new \Vested\Connect\Sdk\Exception\ConnectorException('test stream EOF (scripted)');
            }
            return $next;
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

        $daemon = new Daemon($app, grpc: $fakeClient, logger: new NullLogger(), drainGraceSeconds: 1);
        $exit = $daemon->run();
        expect($exit)->toBe(0);
        expect($fakeClient->opened)->toBeTrue();
        expect($fakeClient->closed)->toBeTrue();

        // First two outbound: Hello + Register
        expect($fakeClient->outbound[0]->getBody())->toBe('hello');
        expect($fakeClient->outbound[1]->getBody())->toBe('register');
    });
})->skip(! extension_loaded('swoole'), 'Swoole not installed');

it('reports handshakeCompleted=true after a clean Hello+Register session', function () {
    \Swoole\Coroutine\run(function () {
        $helloAck = new HubMsg();
        $helloAck->setHelloAck(new HelloAck());
        $regAck = new HubMsg();
        $regAck->setRegisterAck((new RegisterAck())->setStatus('accepted'));

        $fakeClient = buildFakeGrpcClient([$helloAck, $regAck, null]);

        $app = ConnectorApp::create()
            ->withLogger(new NullLogger())
            ->agent('h.s')
                ->withTool(
                    key: 'h.s.k', name: 'K', description: '',
                    inputSchema:  ['type' => 'object'],
                    outputSchema: ['type' => 'object'],
                    handler: fn (array $a, ToolContext $c) => [],
                )
            ->endAgent()
            ->build();

        $daemon = new Daemon($app, grpc: $fakeClient, logger: new NullLogger(), drainGraceSeconds: 1);
        $daemon->run();
        // After RegisterAck the flag must be true — the supervisor uses this
        // to decide whether to reset its backoff between reconnects.
        expect($daemon->handshakeCompleted())->toBeTrue();
    });
})->skip(! extension_loaded('swoole'), 'Swoole not installed');

it('reports handshakeCompleted=false when the stream dies before HelloAck', function () {
    \Swoole\Coroutine\run(function () {
        // Empty inbound script → first recv() throws ConnectorException →
        // we never see HelloAck → handshake never completed.
        $fakeClient = buildFakeGrpcClient([]);

        $app = ConnectorApp::create()
            ->withLogger(new NullLogger())
            ->agent('h.f')
                ->withTool(
                    key: 'h.f.k', name: 'K', description: '',
                    inputSchema:  ['type' => 'object'],
                    outputSchema: ['type' => 'object'],
                    handler: fn (array $a, ToolContext $c) => [],
                )
            ->endAgent()
            ->build();

        $daemon = new Daemon($app, grpc: $fakeClient, logger: new NullLogger(), drainGraceSeconds: 1);
        $daemon->run();
        // Pre-handshake death → supervisor should back off (not reset).
        expect($daemon->handshakeCompleted())->toBeFalse();
    });
})->skip(! extension_loaded('swoole'), 'Swoole not installed');

it('does not uninstall an externally-owned SignalHandler', function () {
    \Swoole\Coroutine\run(function () {
        $helloAck = new HubMsg();
        $helloAck->setHelloAck(new HelloAck());
        $regAck = new HubMsg();
        $regAck->setRegisterAck((new RegisterAck())->setStatus('accepted'));

        $fakeClient = buildFakeGrpcClient([$helloAck, $regAck, null]);

        $app = ConnectorApp::create()
            ->withLogger(new NullLogger())
            ->agent('s.x')
                ->withTool(
                    key: 's.x.k', name: 'K', description: '',
                    inputSchema:  ['type' => 'object'],
                    outputSchema: ['type' => 'object'],
                    handler: fn (array $a, ToolContext $c) => [],
                )
            ->endAgent()
            ->build();

        // Supervisor-owned signal handler — installed once, shared across
        // reconnect attempts so a SIGTERM during the inter-attempt sleep
        // is still caught. Daemon must NOT uninstall it in cleanup().
        $signals = new \Vested\Connect\Sdk\Swoole\SignalHandler();
        $signals->install();

        $daemon = new Daemon($app, grpc: $fakeClient, logger: new NullLogger(), drainGraceSeconds: 1, signals: $signals);
        $daemon->run();

        // After Daemon's cleanup, the supervisor's signal handler must still
        // function. shouldExit() being readable + still false proves the
        // object wasn't reset and (by design) the underlying Swoole handlers
        // weren't ripped out. The test would otherwise segfault on a
        // mid-cleanup signal handler tear-down.
        expect($signals->shouldExit())->toBeFalse();
        // Clean up the test's own SignalHandler.
        $signals->uninstall();
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

        $daemon = new Daemon($app, grpc: $fakeClient, logger: new NullLogger(), drainGraceSeconds: 1);
        $exit = $daemon->run();
        expect($exit)->toBe(78);  // EX_CONFIG
    });
})->skip(! extension_loaded('swoole'), 'Swoole not installed');
