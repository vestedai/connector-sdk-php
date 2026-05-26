<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Swoole;

use Swoole\Timer;
use Vested\Connect\Sdk\Hub\StreamHandler;

/**
 * Pushes a Heartbeat ConnectorMsg onto the outbound channel every
 * intervalMs. The main coroutine drains the channel and writes to the
 * stream — the timer callback is purely a producer.
 *
 * Replaces v0.1's reader-side heartbeat sender. No race because the
 * channel decouples the timer fire from the stream write, and Swoole
 * timer callbacks run on the main reactor thread under the same
 * coroutine context.
 */
final class HeartbeatTimer
{
    private int $timerId = 0;

    public function __construct(
        private readonly OutboundChannel $channel,
        private readonly int $intervalMs = 20_000,
    ) {}

    public function start(): void
    {
        if ($this->timerId !== 0) {
            return;
        }
        $this->timerId = Timer::tick($this->intervalMs, function (): void {
            // Push from a coroutine so push() can yield cleanly if the
            // channel is full (it shouldn't be under normal load).
            \Swoole\Coroutine::create(function (): void {
                $this->channel->push(StreamHandler::buildHeartbeat());
            });
        });
    }

    public function stop(): void
    {
        if ($this->timerId !== 0) {
            Timer::clear($this->timerId);
            $this->timerId = 0;
        }
    }
}
