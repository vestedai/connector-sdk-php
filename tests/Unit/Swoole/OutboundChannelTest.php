<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Swoole;

use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectorMsg;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\Heartbeat;
use Vested\Connect\Sdk\Swoole\OutboundChannel;

it('round-trips a ConnectorMsg in a coroutine', function () {
    \Swoole\Coroutine\run(function () {
        $ch = new OutboundChannel(capacity: 4);
        $hb = new ConnectorMsg();
        $hb->setHeartbeat(new Heartbeat());

        \Swoole\Coroutine::create(function () use ($ch, $hb) {
            $ch->push($hb);
        });

        $received = $ch->popOrNull(timeoutSeconds: 1.0);
        expect($received)->not->toBeNull();
        expect($received->getBody())->toBe('heartbeat');
    });
})->skip(! extension_loaded('swoole'), 'Swoole not installed');

it('popOrNull returns null on timeout when channel empty', function () {
    \Swoole\Coroutine\run(function () {
        $ch = new OutboundChannel();
        $start = microtime(true);
        $result = $ch->popOrNull(timeoutSeconds: 0.05);
        $elapsed = microtime(true) - $start;
        expect($result)->toBeNull();
        expect($elapsed)->toBeGreaterThan(0.04)->toBeLessThan(0.5);
    });
})->skip(! extension_loaded('swoole'), 'Swoole not installed');

it('close() makes further pops return null', function () {
    \Swoole\Coroutine\run(function () {
        $ch = new OutboundChannel();
        $ch->close();
        expect($ch->popOrNull(timeoutSeconds: 0.01))->toBeNull();
    });
})->skip(! extension_loaded('swoole'), 'Swoole not installed');
