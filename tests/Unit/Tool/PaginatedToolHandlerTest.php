<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Vested\Connect\Sdk\Tool\DatasetCursor;
use Vested\Connect\Sdk\Tool\DatasetPage;
use Vested\Connect\Sdk\Tool\PaginatedToolHandler;
use Vested\Connect\Sdk\Tool\ToolContext;
use Vested\Connect\Sdk\Tool\ToolHandler;

final class FixturePagedTool extends PaginatedToolHandler
{
    /** @param array<string, mixed> $args */
    public function fetchPage(array $args, DatasetCursor $cursor, ToolContext $ctx): DatasetPage
    {
        $start = $cursor->token !== null ? (int) $cursor->token : 0;
        $rows  = [];
        for ($i = $start; $i < $start + 10 && $i < 25; $i++) {
            $rows[] = ['i' => $i];
        }
        $next = ($start + 10 < 25) ? (string) ($start + 10) : null;
        return new DatasetPage($rows, $next, 25);
    }
}

it('is a ToolHandler whose handle() throws and whose fetchPage paginates', function () {
    $h = new FixturePagedTool();
    expect($h)->toBeInstanceOf(ToolHandler::class);

    $ctx = new ToolContext(
        invocationId:   'i1',
        organizationId: '1',
        userId:         '',
        userEmail:      '',
        conversationId: 'c',
        agentKey:       'a',
        toolKey:        't',
        deadlineMs:     5000,
        logger:         new NullLogger(),
        invokedAt:      new DateTimeImmutable(),
    );

    $page = $h->fetchPage(['q' => 'x'], new DatasetCursor(token: null, pageSize: 10), $ctx);
    expect($page->rows)->toHaveCount(10)
        ->and($page->nextCursor)->toBe('10')
        ->and($page->total)->toBe(25);

    $h->handle([], $ctx);
})->throws(\LogicException::class);
