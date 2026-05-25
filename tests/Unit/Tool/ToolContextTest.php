<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Tool;

use DateTimeImmutable;
use Psr\Log\NullLogger;
use Vested\Connect\Sdk\Tool\ToolContext;

it('exposes invocation metadata as readonly properties', function () {
    $ctx = new ToolContext(
        invocationId:   'inv-1',
        organizationId: '7',
        userId:         '11',
        userEmail:      'u@example.com',
        conversationId: 'C1',
        agentKey:       'magento.products',
        toolKey:        'magento.products.search',
        deadlineMs:     5000,
        logger:         new NullLogger(),
        invokedAt:      new DateTimeImmutable('2026-05-25T12:00:00Z'),
    );

    expect($ctx->invocationId)->toBe('inv-1');
    expect($ctx->organizationId)->toBe('7');
    expect($ctx->userEmail)->toBe('u@example.com');
    expect($ctx->isSystemRun())->toBeFalse();
    expect($ctx->callerEmailOrNull())->toBe('u@example.com');
});

it('treats empty user_id as a system run', function () {
    $ctx = new ToolContext(
        invocationId: 'inv-2', organizationId: '7', userId: '', userEmail: '',
        conversationId: 'C2', agentKey: 'x.y', toolKey: 'x.y.t', deadlineMs: 1000,
        logger: new NullLogger(), invokedAt: new DateTimeImmutable(),
    );

    expect($ctx->isSystemRun())->toBeTrue();
    expect($ctx->callerEmailOrNull())->toBeNull();
});
