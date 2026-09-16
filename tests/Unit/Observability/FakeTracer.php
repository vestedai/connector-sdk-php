<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Observability;

/**
 * Fake tracer that records all span lifecycle calls so tests can assert
 * the SDK emits the right spans/attributes/statuses without requiring
 * the real OTel SDK to be installed.
 *
 * Lives in its own file so Composer's PSR-4 autoloader can find it by
 * class name alone. Declared inline inside TracingTest.php, it was only
 * ever defined as a side effect of Pest requiring that file — present
 * for tests/Unit/Tool/ToolDispatcherTracingTest.php only when a run also
 * covers tests/Unit/Observability (e.g. --testsuite=Unit), and undefined
 * for a directory-subset run of tests/Unit/Tool alone.
 */
final class FakeTracer
{
    /** @var list<FakeSpan> */
    public array $spans = [];
    public function spanBuilder(string $name): FakeSpanBuilder
    {
        $b = new FakeSpanBuilder($name, $this);
        return $b;
    }
}
final class FakeSpanBuilder
{
    public function __construct(public string $name, public FakeTracer $tracer) {}
    public function startSpan(): FakeSpan
    {
        $s = new FakeSpan($this->name);
        $this->tracer->spans[] = $s;
        return $s;
    }
}
final class FakeSpan
{
    /** @var array<string, mixed> */
    public array $attributes = [];
    /** @var list<\Throwable> */
    public array $exceptions = [];
    /** @var array{0: string, 1: string}|null */
    public ?array $status = null;
    public bool $ended = false;
    public function __construct(public string $name) {}
    public function setAttribute(string $k, mixed $v): self { $this->attributes[$k] = $v; return $this; }
    public function recordException(\Throwable $e): self { $this->exceptions[] = $e; return $this; }
    public function setStatus(string $code, string $description = ''): self { $this->status = [$code, $description]; return $this; }
    public function end(): void { $this->ended = true; }
}
