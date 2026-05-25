<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Process;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ToolCallRequest;
use Vested\Connect\Sdk\Tool\ToolDispatcher;

/**
 * Forked-child entrypoint. Loops on its IPC socket reading
 * ToolCallRequest, handing to the dispatcher, writing ToolCallResponse.
 * Exits cleanly on EOF (parent closed) or SIGTERM.
 *
 * @internal
 */
final class WorkerProcess
{
    private bool $shouldExit = false;

    /**
     * @param  resource  $socket
     */
    public function __construct(
        private $socket,
        private readonly ToolDispatcher $dispatcher,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function (): void {
                $this->shouldExit = true;
            });
        }
    }

    public function run(): void
    {
        while (! $this->shouldExit) {
            if (! $this->processOne()) {
                return;
            }
        }
    }

    /** Process exactly one request. Returns false on EOF or error. Used by tests + run(). */
    public function processOne(): bool
    {
        $req = Ipc::readMessage($this->socket, ToolCallRequest::class);
        if ($req === null) {
            return false;
        }
        $resp = $this->dispatcher->dispatch($req);
        try {
            Ipc::writeMessage($this->socket, $resp);
        } catch (\Throwable $e) {
            $this->logger->error('worker write failed', ['exception' => $e->getMessage()]);
            return false;
        }
        return true;
    }
}
