<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Swoole;

use Swoole\Coroutine\Channel;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectorMsg;

/**
 * Thin facade over Swoole\Coroutine\Channel that carries ConnectorMsg
 * frames from per-call coroutines (and the HeartbeatTimer) to the main
 * coroutine for transmission to the hub.
 *
 * Capacity is bounded to provide backpressure: if the channel is full,
 * push() blocks the producer coroutine until the main coroutine drains
 * one. Default 256 is plenty for typical workloads.
 */
final class OutboundChannel
{
    private readonly Channel $channel;

    public function __construct(int $capacity = 256)
    {
        $this->channel = new Channel($capacity);
    }

    /**
     * Push a frame onto the channel. Blocks the calling coroutine if the
     * channel is full (cooperative backpressure).
     */
    public function push(ConnectorMsg $msg): bool
    {
        return $this->channel->push($msg);
    }

    /**
     * Pop one frame or null on timeout. The main coroutine drains in a
     * loop; the timeout lets it interleave with stream reads.
     */
    public function popOrNull(float $timeoutSeconds = -1.0): ?ConnectorMsg
    {
        $result = $this->channel->pop($timeoutSeconds);
        if ($result === false) {
            return null;  // timeout or closed
        }
        if (! $result instanceof ConnectorMsg) {
            return null;
        }
        return $result;
    }

    public function close(): void
    {
        $this->channel->close();
    }

    public function isFull(): bool
    {
        return $this->channel->isFull();
    }

    public function length(): int
    {
        return $this->channel->length();
    }
}
