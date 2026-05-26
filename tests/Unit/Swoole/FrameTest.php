<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Swoole;

use Vested\Connect\Sdk\Swoole\Frame;

it('encodes a payload with a 5-byte gRPC header', function () {
    $encoded = Frame::encode('hello');
    expect(strlen($encoded))->toBe(10);                      // 5 header + 5 body
    expect(ord($encoded[0]))->toBe(0);                       // compression flag
    expect(substr($encoded, 5))->toBe('hello');
});

it('encodes the length as 4-byte big-endian', function () {
    $body    = str_repeat('A', 300);
    $encoded = Frame::encode($body);
    // bytes 1-4 = big-endian length
    expect(ord($encoded[1]))->toBe(0);
    expect(ord($encoded[2]))->toBe(0);
    expect(ord($encoded[3]))->toBe(1);  // 0x012C = 300
    expect(ord($encoded[4]))->toBe(44);
});

it('decodes a frame to its payload', function () {
    $encoded = Frame::encode('round trip');
    $body    = Frame::decode($encoded);
    expect($body)->toBe('round trip');
});

it('decode rejects a short buffer', function () {
    expect(fn () => Frame::decode("\0\0\0"))->toThrow(\InvalidArgumentException::class);
});

it('decode rejects a wrong length header', function () {
    // Header says length=10, but only 3 bytes of body present
    expect(fn () => Frame::decode("\0\0\0\0\x0Aabc"))->toThrow(\InvalidArgumentException::class);
});
