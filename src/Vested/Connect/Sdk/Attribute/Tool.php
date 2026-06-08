<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Tool
{
    /**
     * @param array<string, mixed>|null $inputSchema
     * @param array<string, mixed>|null $outputSchema
     */
    public function __construct(
        public string $agentKey,
        public string $key,
        public string $name,
        public string $description = '',
        public string $inputSchemaFile = '',
        public string $outputSchemaFile = '',
        public ?array $inputSchema = null,
        public ?array $outputSchema = null,
        public int    $deadlineMs = 30000,
        public int    $maxResultBytes = 1048576,
        public string $sensitivity = '',
    ) {}
}
