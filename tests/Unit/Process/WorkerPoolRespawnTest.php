<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Process;

use Psr\Log\NullLogger;
use Vested\Connect\Sdk\Process\WorkerPool;

/**
 * Deterministic approach: kill a worker with SIGKILL directly via posix_kill,
 * then force reapPending=true by sending SIGCHLD to ourselves (or by calling
 * a reflected setter), and confirm the pool respawns back to N workers.
 */
it('respawns workers that exit unexpectedly', function () {
    // Workers block forever until parent closes the socket (or SIGKILL arrives).
    $pool = new WorkerPool(
        size: 2,
        spawn: function ($childSocket): void {
            while (! feof($childSocket)) {
                @fread($childSocket, 1024);
                usleep(50_000);
            }
            exit(0);
        },
        logger: new NullLogger(),
    );
    $pool->start();
    expect($pool->idleCount())->toBe(2);

    // Grab the pid of one worker and SIGKILL it.
    $sockets = $pool->allSockets();
    expect($sockets)->toHaveCount(2);
    $targetPid = $pool->pidForSocket($sockets[0]);
    expect($targetPid)->not->toBeNull();
    assert($targetPid !== null);

    posix_kill($targetPid, SIGKILL);

    // Wait up to 3 s for the SIGCHLD to arrive, for reapDeadWorkers() to fire,
    // and for the fresh child to be registered.
    $deadline = microtime(true) + 3.0;
    while (microtime(true) < $deadline) {
        $pool->reapDeadWorkers();
        // Once the dead pid is replaced, allSockets() must return 2 again.
        if (count($pool->allSockets()) === 2) {
            break;
        }
        usleep(50_000);
    }

    // Final check: pool size invariant restored.
    $pool->reapDeadWorkers();
    expect(count($pool->allSockets()))->toBe(2);

    $pool->shutdown(timeoutSeconds: 3);
})->skip(! function_exists('pcntl_fork'), 'requires pcntl');

it('does not respawn when shutdown is in progress', function () {
    $pool = new WorkerPool(
        size: 2,
        spawn: function ($childSocket): void {
            while (! feof($childSocket)) {
                @fread($childSocket, 1024);
                usleep(50_000);
            }
            exit(0);
        },
        logger: new NullLogger(),
    );
    $pool->start();
    expect(count($pool->allSockets()))->toBe(2);

    $pool->shutdown(timeoutSeconds: 3);
    expect(count($pool->allSockets()))->toBe(0);
})->skip(! function_exists('pcntl_fork'), 'requires pcntl');
