<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Swoole;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectorMsg;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ToolCallRequest;
use Vested\Connect\Sdk\Observability\Tracing;
use Vested\Connect\Sdk\Tool\ToolDispatcher;
use Vested\Connect\Sdk\Tool\ToolRegistry;

/**
 * Coroutine-aware wrapper around Tool\ToolDispatcher.
 *
 * Each call to dispatch() spawns a fresh coroutine that runs the user's
 * handler. The handler can yield on any I/O (DB, HTTP, file) and other
 * tool calls keep progressing. On completion the response is pushed onto
 * the outbound channel; the main coroutine drains and writes to the stream.
 *
 * Exceptions are guaranteed to become ToolCallResponse{error}; the
 * coroutine never propagates them up to the main loop.
 */
final class CoroutineDispatcher
{
    private readonly ToolDispatcher $inner;

    /**
     * @param array<string, array{input_schema: array<string,mixed>, output_schema: array<string,mixed>}> $toolMeta
     */
    public function __construct(
        ToolRegistry $registry,
        array $toolMeta,
        private readonly OutboundChannel $outbound,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?Tracing $tracing = null,
    ) {
        $this->inner = new ToolDispatcher($registry, $toolMeta, $this->logger, $tracing);
    }

    public function dispatch(ToolCallRequest $req): void
    {
        \Swoole\Coroutine::create(function () use ($req) {
            try {
                $resp = $this->inner->dispatch($req);
            } catch (\Throwable $e) {
                $this->logger->error('coroutine dispatcher caught uncaught exception', [
                    'invocation_id' => $req->getInvocationId(),
                    'exception'     => $e->getMessage(),
                ]);
                $resp = new \Vested\Connect\Sdk\Generated\Proto\Vested\V1\ToolCallResponse();
                $resp->setInvocationId($req->getInvocationId());
                $resp->setError('coroutine_dispatch_failed: ' . substr($e->getMessage(), 0, 1024));
            }

            $out = new ConnectorMsg();
            $out->setToolCallResponse($resp);
            $this->outbound->push($out);
        });
    }
}
