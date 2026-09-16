<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Observability;

use Vested\Connect\Sdk\Observability\Tracing;

it('returns the work result and ends the span on happy path', function () {
    $tracer = new FakeTracer();
    $t = new Tracing($tracer);
    $out = $t->span('test.a', fn () => 'hello', ['k' => 'v']);
    expect($out)->toBe('hello');
    expect($tracer->spans)->toHaveCount(1);
    expect($tracer->spans[0]->ended)->toBeTrue();
    expect($tracer->spans[0]->attributes)->toBe(['k' => 'v']);
});

it('records exception, sets ERROR status, ends span, and re-throws', function () {
    $tracer = new FakeTracer();
    $t = new Tracing($tracer);
    expect(fn () => $t->span('test.b', function () { throw new \RuntimeException('boom'); }))
        ->toThrow(\RuntimeException::class, 'boom');
    expect($tracer->spans[0]->exceptions)->toHaveCount(1);
    expect($tracer->spans[0]->status)->toBe(['ERROR', 'boom']);
    expect($tracer->spans[0]->ended)->toBeTrue();
});

it('is inert when no tracer is configured', function () {
    $t = new Tracing(null);
    $out = $t->span('test.c', fn () => 42, ['x' => 'y']);
    expect($out)->toBe(42);
});

it('start() returns null when no tracer; end() is null-safe', function () {
    $t = new Tracing(null);
    expect($t->start('test.d'))->toBeNull();
    $t->end(null, ['k' => 'v']);  // must not throw
});

it('start()/end() lifecycle records attributes from both calls', function () {
    $tracer = new FakeTracer();
    $t = new Tracing($tracer);
    $span = $t->start('test.e', ['initial' => 1]);
    $t->end($span, ['final' => 2]);
    expect($tracer->spans[0]->attributes)->toBe(['initial' => 1, 'final' => 2]);
    expect($tracer->spans[0]->ended)->toBeTrue();
});

it('end() with exception records and sets status', function () {
    $tracer = new FakeTracer();
    $t = new Tracing($tracer);
    $span = $t->start('test.f');
    $t->end($span, [], new \RuntimeException('late failure'));
    expect($tracer->spans[0]->exceptions)->toHaveCount(1);
    expect($tracer->spans[0]->status)->toBe(['ERROR', 'late failure']);
});
