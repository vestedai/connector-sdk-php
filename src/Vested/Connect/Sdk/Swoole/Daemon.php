<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Swoole;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Vested\Connect\Sdk\ConnectorApp;
use Vested\Connect\Sdk\Exception\TokenException;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\HubMsg;
use Vested\Connect\Sdk\Hub\StreamHandler;
use Vested\Connect\Sdk\Observability\Tracing;

/**
 * The Swoole-runtime daemon. Replaces v0.1's ParentProcess.
 *
 *   1. Opens the gRPC stream
 *   2. Hello/HelloAck
 *   3. Register/RegisterAck
 *   4. Starts heartbeat timer
 *   5. Steady-state loop: drains outbound channel + reads inbound stream
 *      (multiplexed via short-timeout recv() pumps)
 *   6. On signal: graceful drain — wait for in-flight coroutines to
 *      complete (bounded by drainGraceSeconds), then close.
 *
 * Construction takes an opaque $grpc object — production passes a
 * GrpcClient; tests pass a duck-typed stub. The Daemon never imports
 * GrpcClient by name; we duck-type for testability.
 */
final class Daemon
{
    private readonly OutboundChannel $outbound;
    private readonly SignalHandler $signals;
    private readonly HeartbeatTimer $heartbeat;
    private readonly CoroutineDispatcher $dispatcher;

    public function __construct(
        private readonly ConnectorApp $app,
        private readonly object $grpc,  // GrpcClient or test stub
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $drainGraceSeconds = 30,
    ) {
        $this->outbound   = new OutboundChannel();
        $this->signals    = new SignalHandler();
        $this->heartbeat  = new HeartbeatTimer($this->outbound);

        $tracing = new Tracing($this->app->tracer());
        $toolMeta = $this->buildToolMeta();
        $this->dispatcher = new CoroutineDispatcher(
            registry:  $this->app->tools(),
            toolMeta:  $toolMeta,
            outbound:  $this->outbound,
            logger:    $this->logger,
            tracing:   $tracing,
        );
    }

    /** @return array<string, array{input_schema: array<string,mixed>, output_schema: array<string,mixed>}> */
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

    public function run(): int
    {
        $this->signals->install();
        try {
            // 1. Open the stream
            $this->grpc->open();

            // 2. Hello/HelloAck
            $this->grpc->send(StreamHandler::buildHello(
                sdkLanguage: 'php',
                sdkVersion:  $this->sdkVersion(),
                workerId:    gethostname() . ':' . getmypid(),
            ));
            $helloAckMsg = $this->grpc->recv(timeoutSeconds: 10);
            if ($helloAckMsg === null) {
                throw new \RuntimeException('did not receive HelloAck');
            }
            $helloAck = $helloAckMsg->getHelloAck();
            if ($helloAck === null) {
                throw new \RuntimeException('unexpected first frame body: ' . $helloAckMsg->getBody());
            }
            $this->logger->info('connected to hub', [
                'connector_id'   => $helloAck->getConnectorId(),
                'namespace'      => $helloAck->getNamespace(),
                'max_concurrent' => $helloAck->getMaxConcurrentToolCalls(),
            ]);

            // 3. Register
            $this->grpc->send(StreamHandler::buildRegister($this->app));
            $regAckMsg = $this->grpc->recv(timeoutSeconds: 10);
            if ($regAckMsg === null) {
                throw new \RuntimeException('did not receive RegisterAck');
            }
            $regAck = $regAckMsg->getRegisterAck();
            if ($regAck === null) {
                throw new \RuntimeException('unexpected post-Register body: ' . $regAckMsg->getBody());
            }
            if ($regAck->getStatus() !== 'accepted') {
                foreach (StreamHandler::formatRegisterIssues($regAck) as $line) {
                    $this->logger->error('register issue', ['issue' => $line]);
                }
                throw new TokenException('register rejected — see logs for issues');
            }

            // 4. Start heartbeat
            $this->heartbeat->start();

            // 5. Steady-state
            $this->steadyStateLoop();
        } catch (TokenException $e) {
            $this->logger->error('token rejected', ['exception' => $e->getMessage()]);
            $this->cleanup();
            return 78;  // EX_CONFIG
        } catch (\Throwable $e) {
            $this->logger->error('session ended', [
                'exception_class' => $e::class,
                'exception'       => $e->getMessage(),
            ]);
            $this->cleanup();
            return 1;
        }

        $this->cleanup();
        return 0;
    }

    private function steadyStateLoop(): void
    {
        while (! $this->signals->shouldExit()) {
            // Drain outbound (channel) — write up to N at a time before
            // checking inbound. Non-blocking, short timeout.
            while (true) {
                $out = $this->outbound->popOrNull(timeoutSeconds: 0.0);
                if ($out === null) break;
                $this->grpc->send($out);
            }

            // Read one inbound (or short timeout). 100ms keeps us responsive.
            $hub = $this->grpc->recv(timeoutSeconds: 0.1);
            if ($hub === null) {
                if ($this->signals->shouldExit()) break;
                continue;
            }
            $this->handleInbound($hub);
        }

        $this->logger->info('shutdown requested — draining');
        $this->drain();
    }

    private function handleInbound(HubMsg $msg): void
    {
        if (($tcr = $msg->getToolCallRequest()) !== null) {
            $this->dispatcher->dispatch($tcr);
            return;
        }
        if ($msg->getHeartbeatAck() !== null) {
            return;  // no-op
        }
        if (($goAway = $msg->getGoAway()) !== null) {
            $reason = $goAway->getReason();
            $this->logger->warning('GoAway from hub', ['reason' => $reason]);
            if (in_array($reason, ['token_rotated', 'revoked'], true)) {
                throw new TokenException("hub revoked stream: {$reason}");
            }
            throw new \RuntimeException("GoAway: {$reason}");
        }
    }

    private function drain(): void
    {
        $deadline = microtime(true) + $this->drainGraceSeconds;
        while (microtime(true) < $deadline) {
            $out = $this->outbound->popOrNull(timeoutSeconds: 0.2);
            if ($out !== null) {
                try { $this->grpc->send($out); } catch (\Throwable) { /* best-effort */ }
                continue;
            }
            // If there are no more coroutines other than us, exit drain.
            // Swoole\Coroutine::stats()['coroutine_num'] includes the main
            // coroutine; > 1 means there are still per-call coroutines.
            $stats = \Swoole\Coroutine::stats();
            if (($stats['coroutine_num'] ?? 0) <= 1) {
                break;
            }
            \Swoole\Coroutine::sleep(0.05);
        }
        $this->logger->info('drain complete');
    }

    private function cleanup(): void
    {
        $this->heartbeat->stop();
        $this->outbound->close();
        $this->signals->uninstall();
        try { $this->grpc->close(); } catch (\Throwable) {}
    }

    private function sdkVersion(): string
    {
        $composer = json_decode(
            (string) @file_get_contents(__DIR__ . '/../../../../../composer.json'),
            true,
        );
        return (string) ($composer['version'] ?? '0.2.0-dev');
    }
}
