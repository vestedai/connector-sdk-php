<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Observability;

/**
 * Thin duck-typed wrapper around an OpenTelemetry-shaped tracer.
 *
 * If no tracer is configured, all methods are inert. Customers using
 * the official OTel PHP SDK pass an OpenTelemetry\API\Trace\TracerInterface;
 * any object exposing spanBuilder()->startSpan() works.
 */
final class Tracing
{
    public function __construct(private readonly ?object $tracer = null) {}

    /**
     * Wrap a unit of work in a span. Sets exception + status on throw,
     * always ends the span.
     *
     * @template T
     * @param  callable(): T          $work
     * @param  array<string, mixed>   $attributes
     * @return T
     */
    public function span(string $name, callable $work, array $attributes = []): mixed
    {
        if ($this->tracer === null) {
            return $work();
        }
        // @phpstan-ignore-next-line method.notFound (duck-typed OTel tracer)
        $span = $this->tracer->spanBuilder($name)->startSpan();
        try {
            foreach ($attributes as $k => $v) {
                $span->setAttribute($k, $v);
            }
            return $work();
        } catch (\Throwable $e) {
            if (method_exists($span, 'recordException')) {
                // @phpstan-ignore-next-line method.notFound (duck-typed OTel span; method_exists guard ensures safety)
                $span->recordException($e);
            }
            if (method_exists($span, 'setStatus')) {
                // @phpstan-ignore-next-line method.notFound (duck-typed OTel span; method_exists guard ensures safety)
                $span->setStatus('ERROR', $e->getMessage());
            }
            throw $e;
        } finally {
            $span->end();
        }
    }

    /**
     * Lower-level alternative for spans whose lifetime doesn't match a
     * single function call (e.g. tool_call: start at dispatch, end at
     * response receipt). Returns null when no tracer is set; the parent
     * can then no-op on the span handle.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function start(string $name, array $attributes = []): ?object
    {
        if ($this->tracer === null) {
            return null;
        }
        // @phpstan-ignore-next-line method.notFound (duck-typed OTel tracer)
        $span = $this->tracer->spanBuilder($name)->startSpan();
        foreach ($attributes as $k => $v) {
            $span->setAttribute($k, $v);
        }
        return $span;
    }

    /**
     * End a span returned by start(). Null-safe.
     *
     * @param  array<string, mixed>  $finalAttributes
     */
    public function end(?object $span, array $finalAttributes = [], ?\Throwable $exception = null): void
    {
        if ($span === null) {
            return;
        }
        foreach ($finalAttributes as $k => $v) {
            // @phpstan-ignore-next-line method.notFound (duck-typed OTel span)
            $span->setAttribute($k, $v);
        }
        if ($exception !== null) {
            if (method_exists($span, 'recordException')) {
                $span->recordException($exception);
            }
            if (method_exists($span, 'setStatus')) {
                $span->setStatus('ERROR', $exception->getMessage());
            }
        }
        // @phpstan-ignore-next-line method.notFound (duck-typed OTel span)
        $span->end();
    }
}
