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
use Vested\Connect\Sdk\Observability\Tracing;
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

    /**
     * Workers we've SIGTERM'd but haven't yet confirmed dead or SIGKILLed.
     * Maps pid → unix-millisecond timestamp after which to send SIGKILL.
     *
     * @var array<int, int>
     */
    private array $pendingKills = [];

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
        $toolMeta = $this->buildToolMeta();
        $tracing  = new Tracing($this->app->tracer());
        $dispatcher = new ToolDispatcher($this->app->tools(), $toolMeta, $this->logger, $tracing);
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
        /** @var array<string, array{socket: resource, deadlineUnixMs: int, span: ?object}> $inFlight */
        $inFlight = [];

        try {
            // 2. Open the stream + Hello/HelloAck
            $stream = $this->client->openStream();
            $helloAck = null;
            $tracing->span('connector.connect', function () use ($stream, &$helloAck): void {
                // @phpstan-ignore-next-line argument.type (gRPC stub types BidiStreamingCall::write as ByteBuffer; at runtime any Message is accepted)
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
            }, ['sdk.language' => 'php', 'sdk.version' => $this->sdkVersion()]);
            $this->logger->info('connected to hub', [
                'connector_id'   => $helloAck->getConnectorId(),
                'namespace'      => $helloAck->getNamespace(),
                'max_concurrent' => $helloAck->getMaxConcurrentToolCalls(),
            ]);

            // 3. Register
            $tracing->span('connector.register', function () use ($stream): void {
                // @phpstan-ignore-next-line argument.type (gRPC stub types BidiStreamingCall::write as ByteBuffer; at runtime any Message is accepted)
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
            });

            // 4. Steady-state loop
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
                                $tracing->end($inFlight[$invId]['span'], [
                                    'duration_ms' => $resp->getDurationMs(),
                                    'has_error'   => $resp->getError() !== '',
                                ]);
                                $pool->release($inFlight[$invId]['socket']);
                                unset($inFlight[$invId]);
                                $out = new ConnectorMsg();
                                $out->setToolCallResponse($resp);
                                                // @phpstan-ignore-next-line argument.type (gRPC stub types BidiStreamingCall::write as ByteBuffer; at runtime any Message is accepted)
                                $stream->write($out);
                            }
                        }
                    }
                }

                // Enforce per-invocation deadlines
                $this->enforceDeadlines($pool, $inFlight, $stream, $tracing);

                // Process any pending SIGKILL backstops for SIGTERMed workers
                $this->processPendingKills();

                // Reap any dead workers (from deadline-kills above OR natural deaths)
                foreach ($pool->reapDeadWorkers() as $death) {
                    $this->handleWorkerDeath($death, $inFlight, $stream, $tracing);
                }

                // Heartbeat every 30 s
                if (microtime(true) - $lastHeartbeat > 30) {
                    // @phpstan-ignore-next-line argument.type (gRPC stub types BidiStreamingCall::write as ByteBuffer; at runtime any Message is accepted)
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
                $this->handleHubMsg($msg, $pool, $inFlight, $stream, $tracing);
            }
        } finally {
            // Graceful drain: give in-flight invocations up to drainGraceSeconds
            // to complete before tearing down workers. During drain we keep
            // pumping worker responses and forwarding them to the hub so that
            // tool calls that are mid-execution get a real result, not a
            // synthetic error. The pool is NOT shut down until after the drain.
            $drainDeadline = microtime(true) + $this->drainGraceSeconds;
            while (! empty($inFlight) && microtime(true) < $drainDeadline) {
                foreach ($pool->allSockets() as $sock) {
                    $read = [$sock]; $write = null; $except = null;
                    if (stream_select($read, $write, $except, 0, 10_000) > 0) {
                        $resp = Ipc::readMessage($read[0], ToolCallResponse::class);
                        if ($resp !== null) {
                            $invId = $resp->getInvocationId();
                            if (isset($inFlight[$invId])) {
                                $tracing->end(
                                    $inFlight[$invId]['span'] ?? null,
                                    ['duration_ms' => $resp->getDurationMs()],
                                );
                                $pool->release($inFlight[$invId]['socket']);
                                unset($inFlight[$invId]);
                                if ($stream !== null) {
                                    $out = new ConnectorMsg();
                                    $out->setToolCallResponse($resp);
                                    // @phpstan-ignore-next-line argument.type
                                    $stream->write($out);
                                }
                            }
                        }
                    }
                }
                // Reap worker deaths during drain and synthesize error responses.
                foreach ($pool->reapDeadWorkers() as $death) {
                    if ($stream !== null) {
                        $this->handleWorkerDeath($death, $inFlight, $stream, $tracing);
                    }
                }
            }

            if (! empty($inFlight)) {
                $this->logger->warning('drain timeout — abandoning in-flight invocations', [
                    'remaining' => count($inFlight),
                ]);
                if ($stream !== null) {
                    foreach ($inFlight as $invId => $entry) {
                        $resp = new ToolCallResponse();
                        $resp->setInvocationId($invId);
                        $resp->setError('drain_timeout');
                        $out = new ConnectorMsg();
                        $out->setToolCallResponse($resp);
                        // @phpstan-ignore-next-line argument.type
                        $stream->write($out);
                        $tracing->end($entry['span'] ?? null, ['error.kind' => 'drain_timeout']);
                    }
                }
            }

            $pool->shutdown(timeoutSeconds: 5);  // hard worker-process kill after drain
            if ($stream !== null) {
                $stream->writesDone();
                $stream->getStatus();
            }
        }
    }

    /**
     * Handle one inbound HubMsg frame during the steady-state loop.
     *
     * @param  array<string, array{socket: resource, deadlineUnixMs: int, span: ?object}>  $inFlight  (by-ref)
     * @param  object  $stream  duck-typed: must expose write(ConnectorMsg): void
     */
    private function handleHubMsg(HubMsg $msg, WorkerPool $pool, array &$inFlight, $stream, Tracing $tracing): void
    {
        if (($tcr = $msg->getToolCallRequest()) !== null) {
            $sock = $pool->acquire();
            $span = $tracing->start('connector.tool_call', [
                'tool.key'      => $tcr->getToolKey(),
                'agent.key'     => $tcr->getAgentKey(),
                'invocation.id' => $tcr->getInvocationId(),
            ]);
            $inFlight[$tcr->getInvocationId()] = [
                'socket'         => $sock,
                'deadlineUnixMs' => (int) (microtime(true) * 1000) + $tcr->getDeadlineMs(),
                'span'           => $span,
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
     * Synthesize an error response for any invocations past their deadline.
     *
     * The response is written immediately — we do NOT busy-wait for the worker
     * to die. Instead we SIGTERM the worker and record the pid in $pendingKills;
     * processPendingKills() handles the eventual SIGKILL backstop each loop tick.
     *
     * Marked public so it can be exercised in unit tests. Not part of the public API.
     *
     * @internal
     * @param  array<string, array{socket: resource, deadlineUnixMs: int, span: ?object}>  $inFlight  (by-ref)
     * @param  object  $stream  duck-typed: must expose write(ConnectorMsg): void
     */
    public function enforceDeadlines(WorkerPool $pool, array &$inFlight, $stream, Tracing $tracing = new Tracing()): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        foreach ($inFlight as $invId => $entry) {
            if ($entry['deadlineUnixMs'] > $nowMs) {
                continue;
            }
            $pid = $pool->pidForSocket($entry['socket']);
            if ($pid !== null) {
                posix_kill($pid, SIGTERM);
                // Schedule a SIGKILL backstop 2 s from now; processPendingKills() handles it.
                $this->pendingKills[$pid] = (int) ((microtime(true) + 2.0) * 1000);
            }

            // Synthesize the deadline_exceeded response RIGHT NOW — no blocking wait.
            $resp = new ToolCallResponse();
            $resp->setInvocationId($invId);
            $resp->setError('deadline_exceeded');
            $out = new ConnectorMsg();
            $out->setToolCallResponse($resp);
            // @phpstan-ignore-next-line argument.type (gRPC stub types BidiStreamingCall::write as ByteBuffer; at runtime any Message is accepted)
            $stream->write($out);
            $tracing->end(
                $entry['span'] ?? null,
                ['error.kind' => 'deadline_exceeded', 'pid' => $pid ?? -1],
                new \RuntimeException('tool_call_timeout'),
            );
            $this->logger->warning('tool call deadline exceeded', [
                'invocation_id' => $invId, 'pid' => $pid,
            ]);
            unset($inFlight[$invId]);
        }
    }

    /**
     * Periodically check SIGTERMed workers and SIGKILL any that haven't exited
     * within the 2-second grace window. Called each event-loop tick so it never
     * blocks; individual checks are O(1).
     *
     * @internal
     */
    public function processPendingKills(): void
    {
        if (empty($this->pendingKills)) {
            return;
        }
        $nowMs = (int) (microtime(true) * 1000);
        foreach ($this->pendingKills as $pid => $killBy) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === $pid) {
                // Worker already exited after SIGTERM — clean up.
                unset($this->pendingKills[$pid]);
                continue;
            }
            if ($nowMs >= $killBy) {
                posix_kill($pid, SIGKILL);
                unset($this->pendingKills[$pid]);
                // WorkerPool's SIGCHLD handler will reap + respawn.
            }
        }
    }

    /**
     * Synthesize internal_error responses for any invocations whose worker died.
     *
     * @param  array{pid:int, socket:resource, exit_status:int}  $death
     * @param  array<string, array{socket: resource, deadlineUnixMs: int, span: ?object}>  $inFlight  (by-ref)
     * @param  object  $stream  duck-typed: must expose write(ConnectorMsg): void
     */
    private function handleWorkerDeath(array $death, array &$inFlight, $stream, Tracing $tracing): void
    {
        foreach ($inFlight as $invId => $entry) {
            if ($entry['socket'] !== $death['socket']) {
                continue;
            }
            $resp = new ToolCallResponse();
            $resp->setInvocationId($invId);
            $resp->setError(sprintf(
                'internal_error: worker died (pid=%d, exit=%d)',
                $death['pid'],
                $death['exit_status'],
            ));
            $out = new ConnectorMsg();
            $out->setToolCallResponse($resp);
            // @phpstan-ignore-next-line argument.type
            $stream->write($out);
            $tracing->end(
                $entry['span'] ?? null,
                ['error.kind' => 'worker_died', 'pid' => $death['pid']],
                new \RuntimeException('worker died mid-call'),
            );
            $this->logger->warning('invocation lost to worker death', [
                'invocation_id' => $invId, 'pid' => $death['pid'],
            ]);
            unset($inFlight[$invId]);
        }
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
