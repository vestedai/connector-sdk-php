<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Swoole;

use Vested\Connect\Sdk\Exception\ConnectorException;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectorMsg;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\Hello;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\HelloAck;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\HubMsg;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\Register;
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

it('send() chunks a large ConnectorMsg into <=16KB DATA frames', function () {
    $captured = [];
    $fakeHttp2 = new class($captured) {
        public function __construct(public array &$captured) {}
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

    // A Register whose serialized form is well over the 16384 max DATA frame
    // size (mirrors the ~88.6KB real declaration that reset the direct h2c hub).
    $reg = new Register();
    $reg->setBaselineFingerprint(str_repeat('x', 90000));
    $msg = new ConnectorMsg();
    $msg->setRegister($reg);

    $client->send($msg);

    // Emitted as multiple frames, each within the peer's 16384 limit — a single
    // oversized frame is exactly what grpc-go rejects with FRAME_SIZE_ERROR.
    expect(count($fakeHttp2->captured))->toBeGreaterThan(1);
    foreach ($fakeHttp2->captured as $frame) {
        expect(strlen($frame))->toBeLessThanOrEqual(16384);
    }
    // Reassembled across frame boundaries, the bytes decode to the original.
    $body = Frame::decode(implode('', $fakeHttp2->captured));
    $decoded = new ConnectorMsg();
    $decoded->mergeFromString($body);
    expect($decoded->getBody())->toBe('register');
    expect($decoded->getRegister()->getBaselineFingerprint())->toBe(str_repeat('x', 90000));
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

// --- Liveness: the 2026-11-24 incident -------------------------------------
//
// A graceful peer FIN (hub idle-close, hub restart, relay restart) makes
// Swoole's read() return false with errCode 0 while connected stays true —
// indistinguishable from an idle timeout by the errCode/connected check
// alone. The old code read that as "no data yet" and polled forever: in
// production a connector burned 100% CPU for six days in this exact state
// (socket CLOSE_WAIT, no logs, no reconnect) until its pod was restarted.
// A half-open socket (no FIN, no RST) is the same trap with reads that
// time out normally. The defenses are: (1) an errCode-0 read that returns
// in a fraction of its window is a silent EOF -> throw immediately; (2) a
// liveness deadline — the hub acks every 20s heartbeat, so a stream quiet
// for longer than the deadline is dead regardless of what the socket says.

it('recv() throws immediately on a silent EOF (errCode 0, connected, instant return)', function () {
    // The production shape: read() returns false, errCode 0, connected true,
    // in microseconds. The pre-fix code read this as an idle timeout and
    // looped forever; it must now be classified as a closed stream.
    $fakeHttp2 = new class {
        public int $errCode = 0;
        public bool $connected = true;
        public function connect(): bool { return true; }
        public function send($req): int|false { return 1; }
        public function write(int $sid, string $d, bool $e = false): bool { return true; }
        public function read(float $timeout = -1) { return false; }
        public function close(): bool { return true; }
    };

    $client = new GrpcClient(host: 'h', port: 1, token: 't', http2: $fakeHttp2);
    $client->open();

    $t0 = microtime(true);
    $thrown = null;
    try {
        $client->recv(timeoutSeconds: 0.1);
    } catch (ConnectorException $e) {
        $thrown = $e;
    }
    expect($thrown)->not->toBeNull();
    expect((string) $thrown)->toContain('EOF');
    // Fast path, not the deadline: it fires within the same recv() call.
    expect(microtime(true) - $t0)->toBeLessThan(1.0);
    expect($client->isClosed())->toBeTrue();
})->skip(! extension_loaded('swoole'), 'Swoole not installed');

it('recv() declares the stream dead after the liveness deadline when reads keep timing out', function () {
    // The half-open shape: every read is a GENUINE timeout (it consumes the
    // whole window, errCode 0, connected true) — the fast path must NOT
    // fire — but the stream never delivers a frame. Only the liveness
    // deadline can catch this, because the socket layer sees nothing wrong.
    $fakeHttp2 = new class {
        public int $errCode = 0;
        public bool $connected = true;
        public function connect(): bool { return true; }
        public function send($req): int|false { return 1; }
        public function write(int $sid, string $d, bool $e = false): bool { return true; }
        public function read(float $timeout = -1) {
            // Consume the FULL window: a genuine timeout, not an EOF.
            usleep((int) ($timeout * 1_000_000) + 10_000);
            return false;
        }
        public function close(): bool { return true; }
    };

    $client = new GrpcClient(
        host: 'h', port: 1, token: 't',
        http2: $fakeHttp2,
        livenessDeadlineSeconds: 0.5,
    );
    $client->open();

    $deadline = microtime(true) + 5.0; // generous cap: the test must end
    $firstNullAt = null;
    while (true) {
        try {
            $msg = $client->recv(timeoutSeconds: 0.1);
            if ($msg !== null) {
                fail('the fake never delivers a frame');
            }
            $firstNullAt ??= microtime(true);
        } catch (ConnectorException $e) {
            expect((string) $e)->toContain('presumed dead');
            break;
        }
        if (microtime(true) > $deadline) {
            fail('liveness deadline never fired — the pre-fix infinite loop');
        }
    }
    // It fired after real silence past the deadline, not before it.
    expect(microtime(true) - $firstNullAt)->toBeGreaterThanOrEqual(0.4);
    expect($client->isClosed())->toBeTrue();
})->skip(! extension_loaded('swoole'), 'Swoole not installed');

it('recv() returns null on a genuine timeout without tripping the EOF fast path', function () {
    // The inverse guard: a read that legitimately consumes its window must
    // stay a timeout (null), never be misread as a closed stream.
    $fakeHttp2 = new class {
        public int $errCode = 0;
        public bool $connected = true;
        public int $reads = 0;
        public function connect(): bool { return true; }
        public function send($req): int|false { return 1; }
        public function write(int $sid, string $d, bool $e = false): bool { return true; }
        public function read(float $timeout = -1) {
            $this->reads++;
            usleep((int) ($timeout * 1_000_000));
            return false;
        }
        public function close(): bool { return true; }
    };

    $client = new GrpcClient(host: 'h', port: 1, token: 't', http2: $fakeHttp2);
    $client->open();

    expect($client->recv(timeoutSeconds: 0.1))->toBeNull();
    expect($client->isClosed())->toBeFalse();
    // A frame that DOES arrive resets the clock and is returned normally.
    $helloAckMsg = new HubMsg();
    $helloAckMsg->setHelloAck(new HelloAck());
    $framed = Frame::encode($helloAckMsg->serializeToString());
    $withFrame = new class($framed) {
        public int $errCode = 0;
        public bool $connected = true;
        public function __construct(public string $framed) {}
        public function connect(): bool { return true; }
        public function send($req): int|false { return 1; }
        public function write(int $sid, string $d, bool $e = false): bool { return true; }
        public function read(float $timeout = -1) {
            $r = new \stdClass();
            $r->data = $this->framed;
            return $r;
        }
        public function close(): bool { return true; }
    };
    $client2 = new GrpcClient(host: 'h', port: 1, token: 't', http2: $withFrame);
    $client2->open();
    $hub = $client2->recv(timeoutSeconds: 0.1);
    expect($hub)->not->toBeNull();
    expect($hub->getBody())->toBe('hello_ack');
    expect($client2->isClosed())->toBeFalse();
})->skip(! extension_loaded('swoole'), 'Swoole not installed');

it('recv() keeps a shrunken tail-window timeout a timeout after a partial frame (no false EOF)', function () {
    // The false-positive the fast path must NOT produce: a DATA frame that
    // carries only part of a gRPC frame is buffered, not parsed; it eats
    // most of the recv() window; the tail read then times out in a
    // fraction of the 20 ms epsilon. That tail timeout is still a TIMEOUT
    // — the stream is alive and must stay open. (Pre-refinement this exact
    // shape threw "EOF reported without error" and dropped a live stream.)
    $fakeHttp2 = new class {
        public int $errCode = 0;
        public bool $connected = true;
        public int $reads = 0;
        public function connect(): bool { return true; }
        public function send($req): int|false { return 1; }
        public function write(int $sid, string $d, bool $e = false): bool { return true; }
        public function read(float $timeout = -1) {
            $this->reads++;
            if ($this->reads === 1) {
                // 2 bytes: not even a gRPC length prefix — stays buffered.
                $r = new \stdClass();
                $r->data = "\x00\x00";
                usleep(90_000);  // eat most of the 0.1 s window
                return $r;
            }
            // Tail window (~10 ms): a genuine timeout, fully consumed.
            usleep((int) ($timeout * 1_000_000) + 10_000);
            return false;
        }
        public function close(): bool { return true; }
    };

    $client = new GrpcClient(host: 'h', port: 1, token: 't', http2: $fakeHttp2);
    $client->open();

    expect($client->recv(timeoutSeconds: 0.1))->toBeNull();
    expect($client->isClosed())->toBeFalse();
    expect($fakeHttp2->reads)->toBe(2);
})->skip(! extension_loaded('swoole'), 'Swoole not installed');
