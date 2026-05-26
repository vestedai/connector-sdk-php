<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Swoole;

use Vested\Connect\Sdk\Swoole\HeartbeatTimer;
use Vested\Connect\Sdk\Swoole\OutboundChannel;

it('pushes a Heartbeat frame onto the outbound channel on each tick', function () {
    \Swoole\Coroutine\run(function () {
        $ch    = new OutboundChannel();
        $timer = new HeartbeatTimer($ch, intervalMs: 50);
        $timer->start();

        // Wait long enough for ~2 ticks
        \Swoole\Coroutine::sleep(0.15);
        $timer->stop();

        $first = $ch->popOrNull(timeoutSeconds: 0.01);
        $second = $ch->popOrNull(timeoutSeconds: 0.01);
        expect($first?->getBody())->toBe('heartbeat');
        expect($second?->getBody())->toBe('heartbeat');
    });
})->skip(! extension_loaded('swoole'), 'Swoole not installed');

it('stop() halts further ticks', function () {
    \Swoole\Coroutine\run(function () {
        $ch    = new OutboundChannel();
        $timer = new HeartbeatTimer($ch, intervalMs: 30);
        $timer->start();
        \Swoole\Coroutine::sleep(0.05);
        $timer->stop();

        // Drain whatever fired
        while ($ch->popOrNull(timeoutSeconds: 0.001) !== null) {}
        // Sleep long enough that more ticks would have fired if timer was alive
        \Swoole\Coroutine::sleep(0.1);

        expect($ch->popOrNull(timeoutSeconds: 0.01))->toBeNull();
    });
})->skip(! extension_loaded('swoole'), 'Swoole not installed');
