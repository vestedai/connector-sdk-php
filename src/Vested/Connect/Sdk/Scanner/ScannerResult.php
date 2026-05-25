<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Scanner;

use Vested\Connect\Sdk\Agent\AgentBuilder;

final readonly class ScannerResult
{
    /** @param list<AgentBuilder> $agents */
    public function __construct(public array $agents) {}
}
