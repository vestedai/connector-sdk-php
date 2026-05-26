<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Integration;

/**
 * v0.2 integration test — TBD.
 *
 * The v0.1 test used Process\ParentProcess which was removed in v0.2.
 * The v0.2 equivalent (Swoole\Daemon-based live session test) will be
 * added in Task 11 once the Daemon is implemented.
 */
it('v0.2 daemon integration test is TBD (Task 11)', function () {
    $this->markTestSkipped('v0.2 daemon integration test not yet implemented — see Task 11');
})->skip(getenv('INTEGRATION') !== '1', 'set INTEGRATION=1 to run');
