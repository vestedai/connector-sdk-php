<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Process;

use Google\Protobuf\Internal\Message;
use Vested\Connect\Sdk\Exception\ConnectorException;

/**
 * Length-prefixed binary protobuf frame I/O over a Unix-socket pair.
 * Frame layout: uint32 big-endian length, then $length bytes of serialized proto.
 *
 * Returns null on clean EOF; throws ConnectorException on protocol error.
 *
 * @internal
 */
final class Ipc
{
    public static function writeMessage($socket, Message $msg): void
    {
        $body = $msg->serializeToString();
        $len  = strlen($body);
        $hdr  = pack('N', $len);
        $written = fwrite($socket, $hdr . $body);
        if ($written === false) {
            throw new ConnectorException('IPC write failed');
        }
        $total = strlen($hdr) + $len;
        while ($written < $total) {
            $more = fwrite($socket, substr($hdr . $body, $written));
            if ($more === false || $more === 0) {
                throw new ConnectorException('IPC short write');
            }
            $written += $more;
        }
    }

    /**
     * @template T of Message
     * @param  class-string<T>  $messageClass
     * @return T|null
     */
    public static function readMessage($socket, string $messageClass): ?Message
    {
        $hdr = self::readExactly($socket, 4);
        if ($hdr === null) {
            return null;
        }
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', $hdr);
        $len = $unpacked[1];
        if ($len < 0 || $len > 64 * 1024 * 1024) {
            throw new ConnectorException("IPC frame length out of range: {$len}");
        }
        $body = $len > 0 ? self::readExactly($socket, $len) : '';
        if ($body === null) {
            throw new ConnectorException('IPC truncated frame body');
        }
        /** @var T $msg */
        $msg = new $messageClass();
        $msg->mergeFromString($body);
        return $msg;
    }

    private static function readExactly($socket, int $n): ?string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($socket, $n - strlen($buf));
            if ($chunk === false) {
                return null;
            }
            if ($chunk === '') {
                if ($buf === '') {
                    return null;
                }
                throw new ConnectorException("IPC EOF after partial read: have " . strlen($buf) . " of {$n}");
            }
            $buf .= $chunk;
        }
        return $buf;
    }
}
