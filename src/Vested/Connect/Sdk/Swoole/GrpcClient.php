<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Swoole;

use Swoole\Coroutine\Http2\Client as Http2Client;
use Swoole\Http2\Request;
use Vested\Connect\Sdk\Exception\ConnectorException;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectorMsg;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\HubMsg;

/**
 * Bidi gRPC over Swoole's HTTP/2 client.
 *
 * Replaces v0.1's HubClient (which used ext-grpc's BidiStreamingCall).
 * Manages the stream lifecycle:
 *   1. open() — TLS connect + open a stream with gRPC headers
 *   2. send($msg) — frame + write DATA
 *   3. recv($timeout) — read one DATA frame + decode HubMsg
 *   4. close() — half-close + close the connection
 *
 * The http2 client is injected (defaults to Swoole's real client). Tests
 * pass a fake; production passes new Http2Client(...).
 */
final class GrpcClient
{
    private object $http2;
    private int $streamId = 0;
    private string $readBuffer = '';

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $token,
        private readonly bool $insecure = false,
        private readonly int $connectTimeoutSeconds = 10,
        ?object $http2 = null,
    ) {
        $this->http2 = $http2 ?? $this->makeRealClient();
    }

    private function makeRealClient(): Http2Client
    {
        $c = new Http2Client($this->host, $this->port, ! $this->insecure);
        $c->set([
            'timeout'                 => $this->connectTimeoutSeconds,
            'open_eof_check'          => true,
            'http2_keep_alive_pings'  => true,  // HTTP/2 PINGs for liveness
        ]);
        return $c;
    }

    public function open(): void
    {
        if (! $this->http2->connect()) {
            throw new ConnectorException("HTTP/2 connect to {$this->host}:{$this->port} failed");
        }

        // Open a stream. The "request" headers double as the gRPC headers.
        $req = $this->makeStreamRequest();
        $streamId = $this->http2->send($req);
        if ($streamId === false) {
            throw new ConnectorException('failed to open gRPC stream');
        }
        $this->streamId = (int) $streamId;
    }

    private function makeStreamRequest(): Request
    {
        $req = new Request();
        $req->method = 'POST';
        $req->path = '/vested.v1.ConnectorHub/Connect';
        $req->headers = [
            ':path'             => '/vested.v1.ConnectorHub/Connect',
            ':method'           => 'POST',
            'content-type'      => 'application/grpc+proto',
            'te'                => 'trailers',
            'x-connector-token' => $this->token,
            'grpc-encoding'     => 'identity',
        ];
        $req->pipeline = true;  // keep the stream open after send()
        return $req;
    }

    public function send(ConnectorMsg $msg): void
    {
        $payload = $msg->serializeToString();
        $framed  = Frame::encode($payload);
        $ok = $this->http2->write($this->streamId, $framed);
        if (! $ok) {
            throw new ConnectorException('gRPC stream write failed');
        }
    }

    /**
     * Read one HubMsg from the stream. Returns null on timeout or stream
     * close. Coroutine yields while waiting.
     */
    public function recv(float $timeoutSeconds = 30.0): ?HubMsg
    {
        // We may have a partial frame already in the buffer from a previous
        // read; loop until we have a full frame.
        $deadline = microtime(true) + $timeoutSeconds;
        while (true) {
            $framed = $this->tryParseFrame();
            if ($framed !== null) {
                $msg = new HubMsg();
                $msg->mergeFromString($framed);
                return $msg;
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) return null;
            $response = $this->http2->read($remaining);
            if ($response === false || ! isset($response->data)) {
                return null;  // timeout or stream closed
            }
            $this->readBuffer .= $response->data;
        }
    }

    private function tryParseFrame(): ?string
    {
        if (strlen($this->readBuffer) < 5) return null;
        $unpacked = unpack('Cflag/Nlen', substr($this->readBuffer, 0, 5));
        if ($unpacked === false) return null;
        $totalLen = 5 + (int) $unpacked['len'];
        if (strlen($this->readBuffer) < $totalLen) return null;
        $framed = substr($this->readBuffer, 5, $totalLen - 5);
        $this->readBuffer = substr($this->readBuffer, $totalLen);
        return $framed;
    }

    public function close(): void
    {
        @$this->http2->close();
    }
}
