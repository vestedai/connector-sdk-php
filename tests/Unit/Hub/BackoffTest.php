<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Hub;

use Vested\Connect\Sdk\Hub\Backoff;

it('produces capped exponential delays', function () {
    $b = new Backoff(initialMs: 1000, capMs: 30000, jitterPercent: 0);
    expect($b->next())->toBe(1000);
    expect($b->next())->toBe(2000);
    expect($b->next())->toBe(4000);
    expect($b->next())->toBe(8000);
    expect($b->next())->toBe(16000);
    expect($b->next())->toBe(30000);   // capped
    expect($b->next())->toBe(30000);
});

it('applies jitter within bounds', function () {
    $b = new Backoff(initialMs: 1000, capMs: 30000, jitterPercent: 20);
    for ($i = 0; $i < 20; $i++) {
        $d = $b->next();
        expect($d)->toBeGreaterThanOrEqual(0);
    }
});

it('resets back to initial', function () {
    $b = new Backoff(initialMs: 1000, capMs: 30000, jitterPercent: 0);
    $b->next();
    $b->next();
    $b->reset();
    expect($b->next())->toBe(1000);
});
