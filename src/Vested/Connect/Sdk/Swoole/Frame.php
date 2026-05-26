<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Swoole;

/**
 * gRPC framing on HTTP/2 DATA frames.
 *
 * Wire format per the gRPC HTTP/2 spec: 1-byte compression flag + 4-byte
 * big-endian length + N bytes of payload. Always uncompressed for now
 * (compression negotiation is hub-side optional, opt-in v0.3 if needed).
 */
final class Frame
{
    public static function encode(string $payload): string
    {
        return "\x00" . pack('N', strlen($payload)) . $payload;
    }

    /**
     * @throws \InvalidArgumentException if $framed is shorter than the header
     *         or the declared length doesn't match the buffer.
     */
    public static function decode(string $framed): string
    {
        if (strlen($framed) < 5) {
            throw new \InvalidArgumentException('frame shorter than 5-byte header');
        }
        $unpacked = unpack('Cflag/Nlen', substr($framed, 0, 5));
        if ($unpacked === false) {
            throw new \InvalidArgumentException('unable to unpack frame header');
        }
        $len  = (int) $unpacked['len'];
        $body = substr($framed, 5);
        if (strlen($body) !== $len) {
            throw new \InvalidArgumentException("declared length {$len} != body length " . strlen($body));
        }
        return $body;
    }
}
