<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Swoole;

use Vested\Connect\Sdk\Exception\ConnectorException;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectorMsg;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\HubMsg;

/**
 * Test stub for GrpcClient. GrpcClient is final, so this duck-types its public
 * surface instead and is handed to Daemon's constructor.
 *
 * recv() returns the scripted HubMsg frames in order. A null entry in the
 * script signals stream close — recv() throws ConnectorException on it,
 * matching the real GrpcClient's close-detection contract. Reads past the end
 * of the script throw too, so a test can never busy-loop.
 *
 * A named class in its own file, not a helper function inside DaemonTest.php:
 * PSR-4 autoloads classes but not functions, so the function form only existed
 * once PHPUnit had loaded DaemonTest.php. Running a sibling file on its own
 * died with "Call to undefined function
 * Vested\Connect\Sdk\Tests\Unit\Swoole\buildFakeGrpcClient()" — the same shape
 * as the FakeTracer inlining extracted out of TracingTest.php.
 */
final class FakeGrpcClient
{
    /** @var list<HubMsg|null> */
    public array $inbound;

    /** @var list<ConnectorMsg> */
    public array $outbound = [];

    public bool $opened = false;

    public bool $closed = false;

    /** @param list<HubMsg|null> $scriptedInbound */
    public function __construct(array $scriptedInbound)
    {
        $this->inbound = $scriptedInbound;
    }

    public function open(): void
    {
        $this->opened = true;
    }

    public function send(ConnectorMsg $msg): void
    {
        $this->outbound[] = $msg;
    }

    public function recv(float $timeoutSeconds = 30.0): ?HubMsg
    {
        if ($this->inbound === []) {
            throw new ConnectorException('test stream EOF');
        }

        $next = array_shift($this->inbound);
        if ($next === null) {
            throw new ConnectorException('test stream EOF (scripted)');
        }

        return $next;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
