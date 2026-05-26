<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Swoole;

use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectorMsg;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\Hello;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\HelloAck;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\HubMsg;
use Vested\Connect\Sdk\Swoole\Frame;
use Vested\Connect\Sdk\Swoole\GrpcClient;

it('open() initializes the stream with the right headers', function () {
    $fakeHttp2 = new class {
        public bool $connected = false;
        public array $headers = [];
        public int $streamId = 0;

        public function connect(): bool { $this->connected = true; return true; }
        public function send($req): int|false {
            $this->headers = $req->headers;
            $this->streamId = 7;
            return 7;
        }
        public function read(float $timeout = -1) { return false; }
        public function write(int $streamId, string $data, bool $end = false): bool { return true; }
        public function close(): bool { return true; }
    };

    $client = new GrpcClient(
        host: 'hub.example.com',
        port: 4443,
        token: 'eyJ.test.sig',
        http2: $fakeHttp2,
    );
    $client->open();

    expect($fakeHttp2->connected)->toBeTrue();
    expect($fakeHttp2->headers[':path'])->toBe('/vested.v1.ConnectorHub/Connect');
    expect($fakeHttp2->headers['content-type'])->toBe('application/grpc+proto');
    expect($fakeHttp2->headers['x-connector-token'])->toBe('eyJ.test.sig');
})->skip(! extension_loaded('swoole'), 'Swoole not installed');

it('send() frames a ConnectorMsg and writes it as DATA', function () {
    $captured = [];
    $fakeHttp2 = new class($captured) {
        public function __construct(public array &$captured) {}
        public bool $connected = false;
        public function connect(): bool { return true; }
        public function send($req): int|false { return 1; }
        public function read(float $timeout = -1) { return false; }
        public function write(int $streamId, string $data, bool $end = false): bool {
            $this->captured[] = $data; return true;
        }
        public function close(): bool { return true; }
    };

    $client = new GrpcClient(host: 'h', port: 1, token: 't', http2: $fakeHttp2);
    $client->open();

    $hello = new Hello();
    $hello->setSdkLanguage('php');
    $hello->setSdkVersion('0.2.0');
    $hello->setWorkerId('w');
    $msg = new ConnectorMsg();
    $msg->setHello($hello);

    $client->send($msg);
    expect($fakeHttp2->captured)->toHaveCount(1);
    $body = Frame::decode($fakeHttp2->captured[0]);
    $decoded = new ConnectorMsg();
    $decoded->mergeFromString($body);
    expect($decoded->getBody())->toBe('hello');
})->skip(! extension_loaded('swoole'), 'Swoole not installed');

it('recv() reads a DATA frame and decodes it as HubMsg', function () {
    $helloAckMsg = new HubMsg();
    $helloAckMsg->setHelloAck(new HelloAck());
    $framed = Frame::encode($helloAckMsg->serializeToString());

    $fakeHttp2 = new class($framed) {
        public function __construct(public string $framed) {}
        public int $reads = 0;
        public function connect(): bool { return true; }
        public function send($req): int|false { return 1; }
        public function write(int $sid, string $d, bool $e = false): bool { return true; }
        public function read(float $timeout = -1) {
            if ($this->reads++ > 0) return false;
            $r = new \stdClass();
            $r->data = $this->framed;
            $r->pipeline = true;
            return $r;
        }
        public function close(): bool { return true; }
    };

    $client = new GrpcClient(host: 'h', port: 1, token: 't', http2: $fakeHttp2);
    $client->open();
    $client->send(new ConnectorMsg());
    $hub = $client->recv(timeoutSeconds: 0.1);
    expect($hub)->not->toBeNull();
    expect($hub->getBody())->toBe('hello_ack');
})->skip(! extension_loaded('swoole'), 'Swoole not installed');
