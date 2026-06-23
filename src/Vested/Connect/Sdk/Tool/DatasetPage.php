<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tool;

/** One page returned by a PaginatedToolHandler. nextCursor null = last page. */
final class DatasetPage
{
    /** @param list<array<string,mixed>> $rows */
    public function __construct(
        public readonly array $rows,
        public readonly ?string $nextCursor = null,
        public readonly ?int $total = null,
    ) {}
}
