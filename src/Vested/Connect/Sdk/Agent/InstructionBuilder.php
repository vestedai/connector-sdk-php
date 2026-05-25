<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Agent;

/**
 * @internal Immutable container for one instruction. AgentBuilder owns a list of these.
 */
final readonly class InstructionBuilder
{
    public function __construct(
        public string $type,
        public int    $position,
        public string $body,
        public string $format = 'markdown',
    ) {}

    /** @return array{type:string,format:string,body:string,position:int} */
    public function toArray(): array
    {
        return [
            'type'     => $this->type,
            'format'   => $this->format,
            'body'     => $this->body,
            'position' => $this->position,
        ];
    }
}
