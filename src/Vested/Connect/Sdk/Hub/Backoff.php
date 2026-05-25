<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Hub;

/**
 * Exponential backoff: 1s, 2s, 4s, 8s, 16s, 30s cap.
 * jitterPercent ∈ [0, 100] adds ±x% jitter to each interval.
 */
final class Backoff
{
    private int $current;

    public function __construct(
        private readonly int $initialMs = 1000,
        private readonly int $capMs = 30000,
        private readonly int $jitterPercent = 20,
    ) {
        $this->current = $initialMs;
    }

    public function next(): int
    {
        $base = min($this->current, $this->capMs);
        $this->current = min($this->current * 2, $this->capMs);
        if ($this->jitterPercent === 0) {
            return $base;
        }
        $jitterRange = (int) ($base * $this->jitterPercent / 100);
        $jitter = random_int(-$jitterRange, $jitterRange);
        return max(0, $base + $jitter);
    }

    public function reset(): void
    {
        $this->current = $this->initialMs;
    }
}
