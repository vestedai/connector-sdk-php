<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Process;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Vested\Connect\Sdk\ConnectorApp;
use Vested\Connect\Sdk\Exception\TokenException;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectorMsg;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\HubMsg;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ToolCallResponse;
use Vested\Connect\Sdk\Hub\Backoff;
use Vested\Connect\Sdk\Hub\HubClient;
use Vested\Connect\Sdk\Hub\StreamHandler;
use Vested\Connect\Sdk\Tool\ToolDispatcher;

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
     * Runs ONE connection session: spawns workers, opens the gRPC stream,
     * performs Hello/HelloAck and Register/RegisterAck handshake, then
     * drives the steady-state dispatch loop until stream close or SIGTERM.
     */
    public function runOneSession(): void
    {
        // 1. Spawn workers
        $toolMeta   = $this->buildToolMeta();
        $dispatcher = new ToolDispatcher($this->app->tools(), $toolMeta, $this->logger);
        $pool = new WorkerPool(
            size: $this->app->workerPoolSize(),
            spawn: function ($childSocket) use ($dispatcher): void {
                (new WorkerProcess($childSocket, $dispatcher, $this->logger))->run();
                exit(0);
            },
            logger: $this->logger,
        );
        $pool->start();
        $stream = null;

        try {
            // 2. Open the stream + Hello/HelloAck
            $stream = $this->client->openStream();
            $stream->write(StreamHandler::buildHello(
                sdkLanguage: 'php',
                sdkVersion:  $this->sdkVersion(),
                workerId:    gethostname() . ':' . getmypid(),
            ));
            $helloAckMsg = $stream->read();
            if ($helloAckMsg === null || $helloAckMsg->getHelloAck() === null) {
                throw new \RuntimeException('did not receive HelloAck from hub');
            }
            $helloAck = $helloAckMsg->getHelloAck();
            $this->logger->info('connected to hub', [
                'connector_id'   => $helloAck->getConnectorId(),
                'namespace'      => $helloAck->getNamespace(),
                'max_concurrent' => $helloAck->getMaxConcurrentToolCalls(),
            ]);

            // 3. Register
            $stream->write(StreamHandler::buildRegister($this->app));
            $regAckMsg = $stream->read();
            if ($regAckMsg === null || $regAckMsg->getRegisterAck() === null) {
                throw new \RuntimeException('did not receive RegisterAck');
            }
            $regAck = $regAckMsg->getRegisterAck();
            if ($regAck->getStatus() !== 'accepted') {
                foreach (StreamHandler::formatRegisterIssues($regAck) as $line) {
                    $this->logger->error('register issue', ['issue' => $line]);
                }
                throw new TokenException('register rejected — see logs for issues');
            }

            // 4. Steady-state loop
            /** @var array<string, array{socket: resource, deadlineUnixMs: int}> $inFlight */
            $inFlight      = [];
            $lastHeartbeat = microtime(true);

            while (! $this->shouldExit) {
                // Drain worker responses
                foreach ($pool->allSockets() as $sock) {
                    $read = [$sock]; $write = null; $except = null;
                    if (stream_select($read, $write, $except, 0, 1000) > 0) {
                        $resp = Ipc::readMessage($read[0], ToolCallResponse::class);
                        if ($resp !== null) {
                            $invId = $resp->getInvocationId();
                            if (isset($inFlight[$invId])) {
                                $pool->release($inFlight[$invId]['socket']);
                                unset($inFlight[$invId]);
                                $out = new ConnectorMsg();
                                $out->setToolCallResponse($resp);
                                $stream->write($out);
                            }
                        }
                    }
                }

                // Heartbeat every 30 s
                if (microtime(true) - $lastHeartbeat > 30) {
                    $stream->write(StreamHandler::buildHeartbeat());
                    $lastHeartbeat = microtime(true);
                }

                // Pump signal handlers
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }

                // Try to read one inbound frame (non-blocking on the gRPC layer)
                $msg = $stream->read();
                if ($msg === null) {
                    // Stream closed by hub
                    break;
                }
                $this->handleHubMsg($msg, $pool, $inFlight, $stream);
            }
        } finally {
            $pool->shutdown(timeoutSeconds: $this->drainGraceSeconds);
            if ($stream !== null) {
                $stream->writesDone();
                $stream->getStatus();
            }
        }
    }

    /**
     * Handle one inbound HubMsg frame during the steady-state loop.
     *
     * @param  array<string, array{socket: resource, deadlineUnixMs: int}>  $inFlight  (by-ref)
     * @param  \Grpc\BidiStreamingCall  $stream
     */
    private function handleHubMsg(HubMsg $msg, WorkerPool $pool, array &$inFlight, $stream): void
    {
        if (($tcr = $msg->getToolCallRequest()) !== null) {
            $sock = $pool->acquire();
            $inFlight[$tcr->getInvocationId()] = [
                'socket'        => $sock,
                'deadlineUnixMs' => (int) (microtime(true) * 1000) + $tcr->getDeadlineMs(),
            ];
            Ipc::writeMessage($sock, $tcr);
            return;
        }
        if (($goAway = $msg->getGoAway()) !== null) {
            $reason = $goAway->getReason();
            $this->logger->warning('GoAway from hub', ['reason' => $reason]);
            if (in_array($reason, ['token_rotated', 'revoked'], true)) {
                $this->shouldExit = true;
                throw new TokenException("hub revoked stream: {$reason}");
            }
            // idle / hub_draining → throw to trigger reconnect via backoff
            throw new \RuntimeException("GoAway: {$reason}");
        }
        // HeartbeatAck is a no-op for us.
    }

    /**
     * Build the tool-meta map needed by ToolDispatcher from the registered agents.
     *
     * @return array<string, array{input_schema: array<string,mixed>, output_schema: array<string,mixed>}>
     */
    private function buildToolMeta(): array
    {
        $meta = [];
        foreach ($this->app->agents()->declarations() as $agent) {
            foreach ($agent['tools'] as $t) {
                $meta[$t['key']] = [
                    'input_schema'  => $t['input_schema_json'],
                    'output_schema' => $t['output_schema_json'],
                ];
            }
        }
        return $meta;
    }

    private function sdkVersion(): string
    {
        static $cached;
        if ($cached !== null) {
            return $cached;
        }
        // src/Vested/Connect/Sdk/Process/ParentProcess.php → 5 levels up = package root
        $composerPath = __DIR__ . '/../../../../../composer.json';
        if (! is_file($composerPath)) {
            return $cached = '0.1.0-dev';
        }
        $composer = json_decode((string) file_get_contents($composerPath), true);
        return $cached = (string) ($composer['version'] ?? '0.1.0-dev');
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
