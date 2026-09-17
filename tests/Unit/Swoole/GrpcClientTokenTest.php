<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Swoole;

use Vested\Connect\Sdk\Exception\ConnectorException;
use Vested\Connect\Sdk\Swoole\GrpcClient;

/*
 * A trailing newline in the token is not a cosmetic problem.
 *
 * It becomes an HTTP/2 header field value, where CR/LF/NUL are illegal, so the
 * server kills the stream with PROTOCOL_ERROR before dispatching the RPC. The
 * hub's handler never runs and logs nothing; the connector reports only "did
 * not receive HelloAck". On 2026-09-17 that cost an evening — every check of
 * the token said it was correct, because it was, apart from one byte.
 *
 * `kubectl create secret --from-file=...` keeps the file's trailing newline, so
 * this is the DEFAULT shape of a mounted token, not an edge case.
 */
it('refuses a token with a trailing newline, naming the cause', function () {
    expect(fn () => new GrpcClient('hub.example', 4443, "eyJhbGciOi.payload.sig\n", false, 10, new \stdClass()))
        ->toThrow(ConnectorException::class, 'not a legal HTTP/2 header value');
});

it('refuses an embedded carriage return', function () {
    expect(fn () => new GrpcClient('hub.example', 4443, "eyJ\rabc", false, 10, new \stdClass()))
        ->toThrow(ConnectorException::class);
});

it('accepts a clean token', function () {
    $c = new GrpcClient('hub.example', 4443, 'eyJhbGciOi.payload.sig', false, 10, new \stdClass());

    expect($c)->toBeInstanceOf(GrpcClient::class);
});
