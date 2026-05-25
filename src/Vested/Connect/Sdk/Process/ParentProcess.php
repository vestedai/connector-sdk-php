<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Process;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Vested\Connect\Sdk\ConnectorApp;
use Vested\Connect\Sdk\Exception\ConfigException;
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
 *   - the forked stream-reader child that holds the gRPC bidi stream
 *   - the worker pool
 *   - the dispatch event loop
 *   - signal handling (SIGTERM → graceful drain)
 *
 * Public entry point: run() — blocks until SIGTERM or fatal error.
 *
 * The parent NEVER touches gRPC directly. All hub traffic goes through
 * the reader child via a Unix-socket pipe (length-prefixed protobuf
 * frames via Ipc). See {@see StreamReader} for the why.
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
        string $hubAddr,
        bool $insecure = false,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $drainGraceSeconds = 30,
    ) {
        if ($token === '') {
            throw new TokenException('VESTED_CONNECTOR_TOKEN is empty');
        }
        if ($hubAddr === '') {
            throw new ConfigException('hub address is empty — set VESTED_CONNECTOR_HUB or pass --hub-addr');
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
     * Runs ONE connection session:
     *   1. Opens the parent ↔ reader pipe and forks the stream-reader
     *      child (which opens the gRPC stream).
     *   2. Performs Hello/HelloAck via the pipe.
     *   3. Reconciles the requested worker-pool size against the hub's
     *      max_concurrent_tool_calls cap.
     *   4. Spawns the worker pool (after the reader fork, before Register
     *      — see https://github.com/grpc/grpc/issues/31885 for the
     *      forking-after-thread hazard).
     *   5. Sends Register, awaits RegisterAck.
     *   6. Drives the steady-state dispatch loop (stream_select on the
     *      reader pipe + worker sockets) until shutdown.
     */
    public function runOneSession(): void
    {
        // 1a. Open the parent <-> reader pipe.
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new \RuntimeException('parent <-> reader pipe creation failed');
        }
        [$parentEnd, $readerEnd] = $pair;

        // 1b. Fork the stream-reader child BEFORE any worker pool and BEFORE
        //    any gRPC code runs in the parent. The reader is the only
        //    process that touches gRPC.
        $readerPid = pcntl_fork();
        if ($readerPid === -1) {
            @fclose($parentEnd);
            @fclose($readerEnd);
            throw new \RuntimeException('pcntl_fork for stream-reader failed');
        }
        if ($readerPid === 0) {
            // Reader child
            @fclose($parentEnd);
            $reader = new StreamReader($this->client, $this->logger);
            exit($reader->run($readerEnd));
        }
        // Parent
        @fclose($readerEnd);

        $tracing    = new Tracing($this->app->tracer());
        $pool       = null;
        /** @var array<string, array{socket: resource, deadlineUnixMs: int, span: ?object}> $inFlight */
        $inFlight   = [];

        try {
            // 2. Hello/HelloAck via the pipe (synchronous round-trip).
            stream_set_blocking($parentEnd, true);
            Ipc::writeMessage($parentEnd, StreamHandler::buildHello(
                sdkLanguage: 'php',
                sdkVersion:  $this->sdkVersion(),
                workerId:    gethostname() . ':' . getmypid(),
            ));
            $helloAckMsg = $this->readHandshakeFrame($parentEnd, 'HelloAck');
            $helloAck = $helloAckMsg->getHelloAck();
            if ($helloAck === null) {
                throw new \RuntimeException('did not receive HelloAck from hub (got body: ' . $helloAckMsg->getBody() . ')');
            }
            $this->logger->info('connected to hub', [
                'connector_id'   => $helloAck->getConnectorId(),
                'namespace'      => $helloAck->getNamespace(),
                'max_concurrent' => $helloAck->getMaxConcurrentToolCalls(),
            ]);

            // 3. Reconcile the requested pool size against the hub's
            //    max_concurrent_tool_calls cap. Spec: "If the hub's cap
            //    is smaller, log a warning and downsize the pool to match."
            //    A hub cap of 0 or below means "no cap"; we respect the
            //    bootstrap value verbatim.
            $effectivePoolSize = $this->reconcilePoolSize(
                requested:     $this->app->workerPoolSize(),
                hubMaxConcurrent: (int) $helloAck->getMaxConcurrentToolCalls(),
            );

            // 4. Spawn worker pool.
            $toolMeta   = $this->buildToolMeta();
            $dispatcher = new ToolDispatcher($this->app->tools(), $toolMeta, $this->logger, $tracing);
            $pool = new WorkerPool(
                size: $effectivePoolSize,
                spawn: function ($childSocket) use ($dispatcher): void {
                    (new WorkerProcess($childSocket, $dispatcher, $this->logger))->run();
                    exit(0);
                },
                logger: $this->logger,
            );
            $pool->start();

            // 5. Register/RegisterAck via the pipe.
            Ipc::writeMessage($parentEnd, StreamHandler::buildRegister($this->app));
            $regAckMsg = $this->readHandshakeFrame($parentEnd, 'RegisterAck');
            $regAck = $regAckMsg->getRegisterAck();
            if ($regAck === null) {
                throw new \RuntimeException('did not receive RegisterAck (got body: ' . $regAckMsg->getBody() . ')');
            }
            if ($regAck->getStatus() !== 'accepted') {
                foreach (StreamHandler::formatRegisterIssues($regAck) as $line) {
                    $this->logger->error('register issue', ['issue' => $line]);
                }
                throw new TokenException('register rejected — see logs for issues');
            }

            // 6. Steady-state loop.
            stream_set_blocking($parentEnd, false);

            while (! $this->shouldExit) {
                // Note: worker responses are drained via the unified
                // stream_select below; no separate per-worker poll is needed
                // because the select includes every worker socket alongside
                // the reader pipe.

                // Enforce per-invocation deadlines.
                $this->enforceDeadlines($pool, $inFlight, $parentEnd, $tracing);

                // Process any pending SIGKILL backstops for SIGTERMed workers.
                $this->processPendingKills();

                // Reap any dead workers (deadline-kills above OR natural deaths).
                foreach ($pool->reapDeadWorkers() as $death) {
                    $this->handleWorkerDeath($death, $inFlight, $parentEnd, $tracing);
                }

                // Heartbeat sending is owned by the StreamReader, not the
                // parent — see StreamReader::runLoop. The parent's pipe
                // writes can't beat the reader's blocked stream->read()
                // during idle, so we'd never actually get heartbeats out.

                // Pump signal handlers.
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }

                // Multiplex select on reader-pipe + all worker sockets.
                $read = array_merge([$parentEnd], $pool->allSockets());
                $write = null; $except = null;
                $count = @stream_select($read, $write, $except, 0, 100_000);  // 100ms
                if ($count === false || $count === 0) {
                    continue;
                }

                foreach ($read as $sock) {
                    if ($sock === $parentEnd) {
                        // Inbound from reader. To preserve frame boundaries
                        // we flip the pipe to blocking for the framed read.
                        stream_set_blocking($parentEnd, true);
                        $msg = Ipc::readMessage($parentEnd, HubMsg::class);
                        stream_set_blocking($parentEnd, false);
                        if ($msg === null) {
                            // Reader pipe closed unexpectedly — treat as disconnect.
                            throw new \RuntimeException('reader pipe closed unexpectedly');
                        }
                        if ($msg->getBody() === '') {
                            // Empty-body sentinel from the reader = stream closed.
                            throw new \RuntimeException('stream closed by reader child');
                        }
                        if ($msg->getHeartbeatAck() !== null) {
                            // No-op: liveness is delegated to gRPC keepalive.
                            continue;
                        }
                        $this->handleHubMsg($msg, $pool, $inFlight, $parentEnd, $tracing);
                    } else {
                        // Worker response.
                        $resp = Ipc::readMessage($sock, ToolCallResponse::class);
                        if ($resp !== null) {
                            $invId = $resp->getInvocationId();
                            if (isset($inFlight[$invId])) {
                                $tracing->end($inFlight[$invId]['span'] ?? null, [
                                    'duration_ms' => $resp->getDurationMs(),
                                    'has_error'   => $resp->getError() !== '',
                                ]);
                                $pool->release($inFlight[$invId]['socket']);
                                unset($inFlight[$invId]);
                                $out = new ConnectorMsg();
                                $out->setToolCallResponse($resp);
                                Ipc::writeMessage($parentEnd, $out);
                            }
                        }
                    }
                }
            }
        } finally {
            // Graceful drain: give in-flight invocations up to drainGraceSeconds
            // to complete before tearing down workers.
            if ($pool !== null) {
                $drainDeadline = microtime(true) + $this->drainGraceSeconds;
                while (! empty($inFlight) && microtime(true) < $drainDeadline) {
                    foreach ($pool->allSockets() as $sock) {
                        $r = [$sock]; $w = null; $e = null;
                        if (stream_select($r, $w, $e, 0, 10_000) > 0) {
                            $resp = Ipc::readMessage($r[0], ToolCallResponse::class);
                            if ($resp !== null) {
                                $invId = $resp->getInvocationId();
                                if (isset($inFlight[$invId])) {
                                    $tracing->end(
                                        $inFlight[$invId]['span'] ?? null,
                                        ['duration_ms' => $resp->getDurationMs()],
                                    );
                                    $pool->release($inFlight[$invId]['socket']);
                                    unset($inFlight[$invId]);
                                    $out = new ConnectorMsg();
                                    $out->setToolCallResponse($resp);
                                    try {
                                        Ipc::writeMessage($parentEnd, $out);
                                    } catch (\Throwable) {
                                        // pipe may be closed during shutdown; ignore
                                    }
                                }
                            }
                        }
                    }
                    foreach ($pool->reapDeadWorkers() as $death) {
                        $this->handleWorkerDeath($death, $inFlight, $parentEnd, $tracing);
                    }
                }

                if (! empty($inFlight)) {
                    $this->logger->warning('drain timeout — abandoning in-flight invocations', [
                        'remaining' => count($inFlight),
                    ]);
                    foreach ($inFlight as $invId => $entry) {
                        $resp = new ToolCallResponse();
                        $resp->setInvocationId($invId);
                        $resp->setError('drain_timeout');
                        $out = new ConnectorMsg();
                        $out->setToolCallResponse($resp);
                        try {
                            Ipc::writeMessage($parentEnd, $out);
                        } catch (\Throwable) {
                            // ignore
                        }
                        $tracing->end($entry['span'] ?? null, ['error.kind' => 'drain_timeout']);
                    }
                }

                $pool->shutdown(timeoutSeconds: 5);
            }

            // Shut down the reader child: close the pipe (which will cause
            // its non-blocking poll to see EOF) then SIGTERM + reap.
            @fclose($parentEnd);
            if ($readerPid > 0) {
                @posix_kill($readerPid, SIGTERM);
                $deadline = microtime(true) + 2.0;
                while (microtime(true) < $deadline) {
                    $exited = pcntl_waitpid($readerPid, $status, WNOHANG);
                    if ($exited === $readerPid) {
                        break;
                    }
                    usleep(50_000);
                }
                // Backstop SIGKILL if the reader is still alive after the grace.
                if (pcntl_waitpid($readerPid, $status, WNOHANG) === 0) {
                    @posix_kill($readerPid, SIGKILL);
                    pcntl_waitpid($readerPid, $status);
                }
            }
        }
    }

    /**
     * Handle one inbound HubMsg frame during the steady-state loop.
     *
     * @param  array<string, array{socket: resource, deadlineUnixMs: int, span: ?object}>  $inFlight  (by-ref)
     * @param  resource  $pipe  parent end of the reader pipe (outbound ConnectorMsgs go here)
     */
    private function handleHubMsg(HubMsg $msg, WorkerPool $pool, array &$inFlight, $pipe, Tracing $tracing): void
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
        // HeartbeatAck is a no-op (liveness is delegated to gRPC transport keepalive).
    }

    /**
     * Read a handshake response from the reader pipe, skipping HeartbeatAck
     * frames. The reader sends heartbeats independently and the hub acks them;
     * those acks can interleave with the response we're waiting for.
     *
     * @param  resource  $pipe
     */
    private function readHandshakeFrame($pipe, string $expecting): HubMsg
    {
        while (true) {
            $msg = Ipc::readMessage($pipe, HubMsg::class);
            if ($msg === null || $msg->getBody() === '') {
                throw new \RuntimeException("reader exited before {$expecting} — see reader logs");
            }
            if ($msg->getHeartbeatAck() !== null) {
                continue;  // skip; not what we're waiting for
            }
            return $msg;
        }
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
     * @param  resource  $pipe  parent end of the reader pipe
     */
    public function enforceDeadlines(WorkerPool $pool, array &$inFlight, $pipe, Tracing $tracing = new Tracing()): void
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
            Ipc::writeMessage($pipe, $out);
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
     * @param  resource  $pipe  parent end of the reader pipe
     */
    private function handleWorkerDeath(array $death, array &$inFlight, $pipe, Tracing $tracing): void
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
            try {
                Ipc::writeMessage($pipe, $out);
            } catch (\Throwable) {
                // pipe may be closed during shutdown; ignore
            }
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
     * Reconcile the requested worker-pool size against the hub-negotiated
     * max_concurrent_tool_calls cap. Returns the effective pool size to
     * use. Logs a warning whenever we downsize.
     *
     * @internal public for unit testing.
     */
    public function reconcilePoolSize(int $requested, int $hubMaxConcurrent): int
    {
        if ($hubMaxConcurrent <= 0) {
            return $requested;
        }
        $effective = min($requested, $hubMaxConcurrent);
        if ($effective < $requested) {
            $this->logger->warning('downsizing worker pool to match hub cap', [
                'requested' => $requested,
                'hub_cap'   => $hubMaxConcurrent,
                'effective' => $effective,
            ]);
        }
        return $effective;
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
