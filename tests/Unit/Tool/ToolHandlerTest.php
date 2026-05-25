<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Tool;

use DateTimeImmutable;
use Psr\Log\NullLogger;
use Vested\Connect\Sdk\Tool\ToolContext;
use Vested\Connect\Sdk\Tool\ToolHandler;

it('can be implemented by a concrete class', function () {
    $h = new class implements ToolHandler {
        public function handle(array $args, ToolContext $ctx): array
        {
            return ['echoed' => $args['x'] ?? null, 'agent' => $ctx->agentKey];
        }
    };

    $ctx = new ToolContext(
        'inv', '7', '11', 'u@e.com', 'C1', 'a.b', 'a.b.t', 1000,
        new NullLogger(), new DateTimeImmutable(),
    );

    expect($h->handle(['x' => 'hi'], $ctx))->toBe(['echoed' => 'hi', 'agent' => 'a.b']);
});
