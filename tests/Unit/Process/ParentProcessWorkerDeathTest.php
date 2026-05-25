<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Process;

use Psr\Log\NullLogger;
use Vested\Connect\Sdk\Process\WorkerPool;

it('returns dead-worker tuples so the parent can synthesize internal_error', function () {
    $pool = new WorkerPool(
        size: 1,
        spawn: function ($s): void {
            // Block until the socket closes; the parent will SIGKILL us.
            while (! feof($s)) {
                @fread($s, 1024);
                usleep(50_000);
            }
            exit(0);
        },
        logger: new NullLogger(),
    );
    $pool->start();

    $sockets = $pool->allSockets();
    expect($sockets)->toHaveCount(1);
    $targetPid = $pool->pidForSocket($sockets[0]);
    assert($targetPid !== null);

    // Kill the worker externally — same pattern used in WorkerPoolRespawnTest.
    posix_kill($targetPid, SIGKILL);

    // Poll until SIGCHLD fires and reapDeadWorkers() returns the death tuple.
    $deadline = microtime(true) + 3.0;
    $deaths = [];
    while (microtime(true) < $deadline) {
        $batch = $pool->reapDeadWorkers();
        if (count($batch) > 0) {
            $deaths = $batch;
            break;
        }
        usleep(50_000);
    }

    expect($deaths)->toHaveCount(1);
    expect($deaths[0]['pid'])->toBe($targetPid);
    // SIGKILL exit status is non-zero (pcntl_wtermsig would give 9)
    expect(pcntl_wifsignaled($deaths[0]['exit_status']))->toBeTrue();

    $pool->shutdown(timeoutSeconds: 2);
})->skip(! function_exists('pcntl_fork'), 'requires pcntl');
