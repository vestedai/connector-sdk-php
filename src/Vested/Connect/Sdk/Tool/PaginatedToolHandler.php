<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tool;

/**
 * Base for tools that return a large row set. The author implements fetchPage();
 * the hub fetches page 1 for the in-context sample and (later) replays pages to
 * materialize the full set. The single-result handle() is unused.
 * The tool's #[Tool(outputSchema: ...)] describes ONE ROW.
 */
abstract class PaginatedToolHandler implements ToolHandler
{
    /** @param array<string, mixed> $args */
    abstract public function fetchPage(array $args, DatasetCursor $cursor, ToolContext $ctx): DatasetPage;

    /** @param array<string, mixed> $args */
    final public function handle(array $args, ToolContext $ctx): array
    {
        throw new \LogicException('Paginated tool: the hub calls fetchPage(), not handle().');
    }
}
