<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Swoole;

use Swoole\Process;

/**
 * Catches SIGTERM/SIGINT and sets a flag the main loop polls.
 *
 * Replaces v0.1's pcntl_signal handlers. Swoole's signal handler runs
 * on the reactor thread; the flag is read by the main coroutine on
 * every iteration of the steady-state loop.
 */
final class SignalHandler
{
    private bool $shouldExit = false;

    public function install(): void
    {
        Process::signal(SIGTERM, function (): void { $this->shouldExit = true; });
        Process::signal(SIGINT,  function (): void { $this->shouldExit = true; });
    }

    public function uninstall(): void
    {
        Process::signal(SIGTERM, null);
        Process::signal(SIGINT,  null);
    }

    public function shouldExit(): bool
    {
        return $this->shouldExit;
    }
}
