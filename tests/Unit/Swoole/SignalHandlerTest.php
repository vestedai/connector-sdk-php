<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Swoole;

use Vested\Connect\Sdk\Swoole\SignalHandler;

it('shouldExit is false until a signal arrives', function () {
    \Swoole\Coroutine\run(function () {
        $h = new SignalHandler();
        $h->install();
        expect($h->shouldExit())->toBeFalse();
        $h->uninstall();
    });
})->skip(! extension_loaded('swoole'), 'Swoole not installed');

it('SIGTERM flips shouldExit to true', function () {
    \Swoole\Coroutine\run(function () {
        $h = new SignalHandler();
        $h->install();
        \Swoole\Process::kill(posix_getpid(), SIGTERM);
        \Swoole\Coroutine::sleep(0.05);
        expect($h->shouldExit())->toBeTrue();
        $h->uninstall();
    });
})->skip(! extension_loaded('swoole'), 'Swoole not installed');
