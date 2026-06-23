<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tool;

/** Opaque page cursor handed to a paginated tool. token is null on the first page. */
final class DatasetCursor
{
    public function __construct(
        public readonly ?string $token,
        public readonly int $pageSize,
    ) {}
}
