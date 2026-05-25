<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Process;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectorMsg;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\HubMsg;
use Vested\Connect\Sdk\Hub\HubClient;

/**
 * Forked-child process. Owns the bidi gRPC stream and bridges between
 * gRPC and a Unix-socket pipe shared with the parent.
 *
 * Why: ext-grpc's BidiStreamingCall::read() blocks inside libgrpc and
 * can't be interrupted by signals or selected on, so the parent's event
 * loop would be stuck behind every read. Moving the gRPC stream into
 * its own process frees the parent to stream_select on its worker
 * sockets while still seeing inbound frames promptly via the pipe.
 *
 * Loop (see {@see runLoop()}):
 *   1. Non-blocking drain of $parentPipe for outbound ConnectorMsg frames
 *      written by the parent; forward each one to $stream.
 *   2. Blocking $stream->read() for the next HubMsg.
 *   3. On HubMsg: write to $parentPipe (length-prefixed via Ipc).
 *   4. On null (stream closed): write an empty-body HubMsg sentinel back
 *      to $parentPipe and exit so the parent can SIGTERM us and reconnect.
 *
 * @internal
 */
final class StreamReader
{
    private bool $shouldExit = false;

    public function __construct(
        private readonly HubClient $client,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Entry point inside the forked child. Opens the bidi gRPC stream,
     * then delegates to {@see runLoop()}.
     *
     * @param  resource  $parentPipe  Unix socket back to parent.
     */
    public function run($parentPipe): int
    {
        try {
            $stream = $this->client->openStream();
        } catch (\Throwable $e) {
            $this->logger->error('stream open failed in reader child', ['exception' => $e->getMessage()]);
            // Best-effort sentinel so parent doesn't hang waiting on us.
            try {
                Ipc::writeMessage($parentPipe, new HubMsg());
            } catch (\Throwable) {
                // pipe may already be closed; ignore
            }
            return 1;
        }

        return $this->runLoop($stream, $parentPipe);
    }

    /**
     * Inner loop. Public for unit testing — pass a duck-typed stream
     * (anything exposing read(): ?HubMsg, write(Message): void,
     * writesDone(): void, getStatus(): mixed).
     *
     * @param  object    $stream      duck-typed bidi stream
     * @param  resource  $parentPipe
     */
    public function runLoop(object $stream, $parentPipe): int
    {
        // Install SIGTERM handler so the parent can shut us down cleanly
        // between blocking reads. Note: this won't interrupt libgrpc's
        // pthread_cond_timedwait — but the parent only SIGTERMs the
        // reader on reconnect (after the stream's already torn down) or
        // on full daemon shutdown, where killing the child is fine.
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function (): void {
                $this->shouldExit = true;
            });
        }

        // After we forward an inbound HubMsg to the parent, the parent
        // often replies with an outbound (Hello → Register, ToolCallRequest
        // → ToolCallResponse, etc.). Without this hint, the reader would
        // immediately re-block on stream->read() and race the parent — if
        // the parent's write loses, the outbound sits in the pipe and the
        // hub eventually idle-times-out the stream with GoAway.
        //
        // Setting this true causes the next drain pass to use a brief
        // blocking wait instead of returning immediately on an empty pipe.
        $expectOutboundResponse = false;

        while (! $this->shouldExit) {
            // Step 1: drain outbound from parent.
            stream_set_blocking($parentPipe, false);

            // If we just sent something inbound to the parent, give the
            // parent a brief window to respond. ~200ms is plenty for the
            // synchronous handshake round-trips (Hello/HelloAck → Register
            // round-trip) and tolerable as a steady-state ceiling on
            // worker-response forwarding latency.
            if ($expectOutboundResponse) {
                $read = [$parentPipe];
                $write = null;
                $except = null;
                @stream_select($read, $write, $except, 0, 200_000);
                $expectOutboundResponse = false;
            }

            while (true) {
                $msg = $this->readPipeNonBlocking($parentPipe);
                if ($msg === null) {
                    break;
                }
                try {
                    // @phpstan-ignore-next-line argument.type (gRPC stub types BidiStreamingCall::write as ByteBuffer; at runtime any Message is accepted)
                    $stream->write($msg);
                } catch (\Throwable $e) {
                    $this->logger->error('reader: write to stream failed', ['exception' => $e->getMessage()]);
                    break 2;
                }
            }
            // Re-block parent-pipe so subsequent Ipc::writeMessage calls behave normally.
            stream_set_blocking($parentPipe, true);

            // Step 2: blocking read from hub.
            /** @var ?HubMsg $hub */
            // @phpstan-ignore-next-line method.notFound (duck-typed: BidiStreamingCall::read at runtime)
            $hub = $stream->read();
            if ($hub === null) {
                // Stream ended (EOF / DEADLINE_EXCEEDED / hub close). Notify
                // parent via empty-body sentinel and exit.
                try {
                    Ipc::writeMessage($parentPipe, new HubMsg());
                } catch (\Throwable $e) {
                    $this->logger->warning('reader: sentinel write failed', ['exception' => $e->getMessage()]);
                }
                break;
            }

            // Step 3: forward to parent.
            try {
                Ipc::writeMessage($parentPipe, $hub);
                $expectOutboundResponse = true;
            } catch (\Throwable $e) {
                $this->logger->error('reader: forward to parent failed', ['exception' => $e->getMessage()]);
                break;
            }
        }

        try {
            // @phpstan-ignore-next-line method.notFound (duck-typed: BidiStreamingCall::writesDone at runtime)
            $stream->writesDone();
            // @phpstan-ignore-next-line method.notFound (duck-typed: BidiStreamingCall::getStatus at runtime)
            $stream->getStatus();
        } catch (\Throwable) {
            // best-effort
        }
        @fclose($parentPipe);
        return 0;
    }

    /**
     * Non-blocking poll of the parent pipe for one outbound ConnectorMsg.
     * Returns null if no full frame is ready (rather than blocking).
     *
     * We can't just call Ipc::readMessage on a non-blocking pipe because
     * Ipc treats fread === '' as EOF; on a non-blocking pipe with no
     * data, fread returns '' too. So we use stream_select with timeout=0
     * to check first, then do a blocking-style read of the full frame
     * (the kernel buffer already has it since stream_select said so).
     *
     * @param  resource  $pipe
     */
    private function readPipeNonBlocking($pipe): ?ConnectorMsg
    {
        $read = [$pipe];
        $write = null;
        $except = null;
        $count = @stream_select($read, $write, $except, 0, 0);
        if ($count === false || $count === 0) {
            return null;
        }
        // There's data available; flip to blocking for the framed read so
        // a partially-arrived header doesn't cause an EOF false positive.
        stream_set_blocking($pipe, true);
        try {
            return Ipc::readMessage($pipe, ConnectorMsg::class);
        } finally {
            stream_set_blocking($pipe, false);
        }
    }
}
