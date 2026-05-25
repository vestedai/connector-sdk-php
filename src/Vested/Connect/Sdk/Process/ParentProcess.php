<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Process;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Vested\Connect\Sdk\ConnectorApp;
use Vested\Connect\Sdk\Exception\TokenException;
use Vested\Connect\Sdk\Hub\Backoff;
use Vested\Connect\Sdk\Hub\HubClient;

/**
 * The daemon's main process. Owns:
 *   - the long-lived bidi gRPC stream to the hub
 *   - the worker pool
 *   - the dispatch event loop
 *   - signal handling (SIGTERM → graceful drain)
 *
 * Public entry point: run() — blocks until SIGTERM or fatal error.
 */
final class ParentProcess
{
    private readonly HubClient $client;
    private readonly Backoff $backoff;
    private bool $shouldExit = false;

    public function __construct(
        private readonly ConnectorApp $app,
        string $token,
        string $hubAddr = 'ai-connect.alsaifgallery.com:4443',
        bool $insecure = false,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $drainGraceSeconds = 30,
    ) {
        if ($token === '') {
            throw new TokenException('VESTED_CONNECTOR_TOKEN is empty');
        }
        $this->client  = new HubClient($hubAddr, $token, $insecure);
        $this->backoff = new Backoff();
    }

    public function requestedWorkerPoolSize(): int
    {
        return $this->app->workerPoolSize();
    }

    /** Main entry point. Blocks until SIGTERM or fatal error. */
    public function run(): int
    {
        $this->installSignalHandlers();
        while (! $this->shouldExit) {
            try {
                $this->runOneSession();
                $this->backoff->reset();
            } catch (\Throwable $e) {
                $this->logger->error('session ended', ['exception' => $e->getMessage()]);
                if ($this->shouldExit) {
                    break;
                }
                $delayMs = $this->backoff->next();
                $this->logger->info('reconnecting after backoff', ['delay_ms' => $delayMs]);
                usleep($delayMs * 1000);
            }
        }
        return 0;
    }

    /**
     * Runs ONE connection session. Real implementation lands in Task 20;
     * here it's a placeholder so unit tests can construct ParentProcess
     * without a live hub.
     */
    public function runOneSession(): void
    {
        throw new \LogicException('runOneSession() not yet implemented');
    }

    private function installSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void {
            $this->shouldExit = true;
            $this->logger->info('SIGTERM received; will drain and exit');
        });
        pcntl_signal(SIGINT, function (): void {
            $this->shouldExit = true;
            $this->logger->info('SIGINT received; will drain and exit');
        });
    }
}
