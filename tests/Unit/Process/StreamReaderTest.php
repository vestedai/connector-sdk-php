<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Process;

use Psr\Log\NullLogger;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectorMsg;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\Hello;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\HelloAck;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\HubMsg;
use Vested\Connect\Sdk\Process\Ipc;
use Vested\Connect\Sdk\Process\StreamReader;

it('forwards outbound frames from the pipe to the stream and inbound stream frames to the pipe', function () {
    // Set up the parent <-> reader pipe (we hold the parent end; reader gets readerEnd).
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    assert($pair !== false);
    [$parentEnd, $readerEnd] = $pair;

    // Pre-populate the pipe with one outbound ConnectorMsg (a Hello).
    $hello = new Hello();
    $hello->setSdkLanguage('php');
    $hello->setSdkVersion('test');
    $hello->setWorkerId('w');
    $out = new ConnectorMsg();
    $out->setHello($hello);
    Ipc::writeMessage($parentEnd, $out);

    // Fake stream: captures writes, returns one HubMsg on first read, null on next.
    $helloAckHubMsg = new HubMsg();
    $helloAckHubMsg->setHelloAck(new HelloAck());
    $fake = new class($helloAckHubMsg) {
        /** @var array<int, \Google\Protobuf\Internal\Message> */
        public array $captured = [];
        /** @var array<int, ?HubMsg> */
        public array $reads;
        public bool $writesDoneCalled = false;
        public bool $getStatusCalled = false;

        public function __construct(HubMsg $helloAckHubMsg)
        {
            // Two reads: first returns the HubMsg, second returns null (stream closed).
            $this->reads = [$helloAckHubMsg, null];
        }

        public function write(\Google\Protobuf\Internal\Message $msg): void
        {
            $this->captured[] = $msg;
        }

        public function read(): ?HubMsg
        {
            return array_shift($this->reads);
        }

        public function writesDone(): void
        {
            $this->writesDoneCalled = true;
        }

        public function getStatus(): object
        {
            $this->getStatusCalled = true;
            return (object) ['code' => 0];
        }
    };

    $reader = new StreamReader(
        // We pass a HubClient stub via the loop-entry method, so the constructor argument
        // isn't exercised in this test. Instantiate with a real-ish HubClient.
        client: new \Vested\Connect\Sdk\Hub\HubClient('localhost:9092', 'eyJ.t.s', true),
        logger: new NullLogger(),
    );

    // Drive the loop directly against the fake stream + pipe.
    $exit = $reader->runLoop($fake, $readerEnd);

    expect($exit)->toBe(0);

    // Outbound: filter out heartbeats (reader writes those itself); the
    // Hello from the pipe should still have been forwarded.
    $nonHeartbeat = array_values(array_filter(
        $fake->captured,
        fn ($m) => $m instanceof ConnectorMsg && $m->getBody() !== 'heartbeat',
    ));
    expect($nonHeartbeat)->toHaveCount(1);
    /** @var ConnectorMsg $sent */
    $sent = $nonHeartbeat[0];
    expect($sent->getBody())->toBe('hello');

    // Inbound: the HelloAck HubMsg should have been forwarded to the parent end,
    // followed by the empty-body sentinel marking stream close.
    $forwarded = Ipc::readMessage($parentEnd, HubMsg::class);
    assert($forwarded !== null);
    expect($forwarded->getBody())->toBe('hello_ack');

    $sentinel = Ipc::readMessage($parentEnd, HubMsg::class);
    assert($sentinel !== null);
    expect($sentinel->getBody())->toBe('');

    expect($fake->writesDoneCalled)->toBeTrue();
    expect($fake->getStatusCalled)->toBeTrue();

    @fclose($parentEnd);
    // $readerEnd was closed by the StreamReader itself.
});

it('exits cleanly when the stream returns null immediately', function () {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    assert($pair !== false);
    [$parentEnd, $readerEnd] = $pair;

    // Fake stream returning null on the very first read.
    $fake = new class {
        /** @var array<int, \Google\Protobuf\Internal\Message> */
        public array $captured = [];
        public bool $writesDoneCalled = false;
        public bool $getStatusCalled = false;

        public function write(\Google\Protobuf\Internal\Message $msg): void
        {
            $this->captured[] = $msg;
        }

        public function read(): ?HubMsg
        {
            return null;
        }

        public function writesDone(): void
        {
            $this->writesDoneCalled = true;
        }

        public function getStatus(): object
        {
            $this->getStatusCalled = true;
            return (object) ['code' => 0];
        }
    };

    $reader = new StreamReader(
        client: new \Vested\Connect\Sdk\Hub\HubClient('localhost:9092', 'eyJ.t.s', true),
        logger: new NullLogger(),
    );

    $exit = $reader->runLoop($fake, $readerEnd);
    expect($exit)->toBe(0);

    // Should have written the sentinel HubMsg (empty body) back to the parent.
    $sentinel = Ipc::readMessage($parentEnd, HubMsg::class);
    assert($sentinel !== null);
    expect($sentinel->getBody())->toBe('');

    // No outbound writes other than the reader's own heartbeat.
    $nonHeartbeat = array_filter(
        $fake->captured,
        fn ($m) => $m instanceof ConnectorMsg && $m->getBody() !== 'heartbeat',
    );
    expect($nonHeartbeat)->toBeEmpty();

    @fclose($parentEnd);
});

it('drains multiple outbound frames in a single pipe-poll', function () {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    assert($pair !== false);
    [$parentEnd, $readerEnd] = $pair;

    // Pre-write three outbound ConnectorMsgs.
    foreach (['a', 'b', 'c'] as $id) {
        $tcr = new \Vested\Connect\Sdk\Generated\Proto\Vested\V1\ToolCallResponse();
        $tcr->setInvocationId($id);
        $out = new ConnectorMsg();
        $out->setToolCallResponse($tcr);
        Ipc::writeMessage($parentEnd, $out);
    }

    $fake = new class {
        /** @var array<int, ConnectorMsg> */
        public array $captured = [];
        public function write(\Google\Protobuf\Internal\Message $msg): void
        {
            assert($msg instanceof ConnectorMsg);
            $this->captured[] = $msg;
        }
        public function read(): ?HubMsg
        {
            return null;  // forces immediate sentinel + exit after draining outbound
        }
        public function writesDone(): void {}
        public function getStatus(): object { return (object) ['code' => 0]; }
    };

    $reader = new StreamReader(
        client: new \Vested\Connect\Sdk\Hub\HubClient('localhost:9092', 'eyJ.t.s', true),
        logger: new NullLogger(),
    );
    $reader->runLoop($fake, $readerEnd);

    // All three outbound messages should have been drained and written to
    // the stream. Filter out the reader's own heartbeat before asserting.
    $toolResponses = array_values(array_filter(
        $fake->captured,
        fn (ConnectorMsg $m) => $m->getBody() === 'tool_call_response',
    ));
    expect($toolResponses)->toHaveCount(3);
    $ids = array_map(
        fn (ConnectorMsg $m): string => $m->getToolCallResponse()?->getInvocationId() ?? '',
        $toolResponses,
    );
    expect($ids)->toBe(['a', 'b', 'c']);

    @fclose($parentEnd);
});
