<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Agent;

use Closure;
use Vested\Connect\Sdk\ConnectorApp;
use Vested\Connect\Sdk\Exception\ConfigException;
use Vested\Connect\Sdk\Tool\ToolHandler;

/** @internal Allowed values for the tool sensitivity field. */
const TOOL_SENSITIVITY_VALUES = ['read', 'write', 'destructive', 'external_call', 'medium'];

/**
 * Per-agent chained builder. Returned by ConnectorApp::agent($key).
 * toDeclaration() produces the wire-shape dict that the Register frame
 * carries; build() validates internal consistency.
 */
final class AgentBuilder
{
    private string $name = '';
    private string $description = '';
    private string $status = 'active';
    /** @var array{provider:string,name:string,config:array<string,mixed>}|null */
    private ?array $model = null;
    /** @var list<InstructionBuilder> */
    private array $instructions = [];
    /** @var list<array<string,mixed>> */
    private array $tools = [];
    /** @var array<string, Closure|ToolHandler> */
    private array $handlers = [];
    private ?ConnectorApp $parentApp = null;

    public function __construct(public readonly string $key) {}

    public function name(string $name): self { $this->name = $name; return $this; }
    public function description(string $description): self { $this->description = $description; return $this; }
    public function status(string $status): self { $this->status = $status; return $this; }

    /** @param  array<string,mixed>  $config */
    public function withModel(string $provider, string $name, array $config = []): self
    {
        $this->model = ['provider' => $provider, 'name' => $name, 'config' => $config];
        return $this;
    }

    public function withInstruction(
        string $body,
        string $type = 'system',
        int $position = 0,
        string $format = 'markdown',
    ): self {
        $this->instructions[] = new InstructionBuilder(type: $type, position: $position, body: $body, format: $format);
        return $this;
    }

    /**
     * @param  array<string,mixed>           $inputSchema
     * @param  array<string,mixed>           $outputSchema
     * @param  Closure|ToolHandler           $handler
     */
    public function withTool(
        string $key,
        string $name,
        string $description,
        array $inputSchema,
        array $outputSchema,
        Closure|ToolHandler $handler,
        int $deadlineMs = 30000,
        int $maxResultBytes = 1048576,
        string $sensitivity = '',
    ): self {
        if ($sensitivity !== '' && ! in_array($sensitivity, TOOL_SENSITIVITY_VALUES, true)) {
            throw new ConfigException(
                "tool '{$key}': invalid sensitivity '{$sensitivity}'. "
                . 'Allowed values: ' . implode(', ', TOOL_SENSITIVITY_VALUES) . '. '
                . "Empty string means unset (hub defaults to 'external_call')."
            );
        }
        $this->tools[] = [
            'key'                 => $key,
            'name'                => $name,
            'description'         => $description,
            'input_schema_json'   => $inputSchema,
            'output_schema_json'  => $outputSchema,
            'default_deadline_ms' => $deadlineMs,
            'max_result_bytes'    => $maxResultBytes,
            'sensitivity'         => $sensitivity,
        ];
        $this->handlers[$key] = $handler;
        return $this;
    }

    public function handlerFor(string $toolKey): Closure|ToolHandler|null
    {
        return $this->handlers[$toolKey] ?? null;
    }

    /** @return array<string, Closure|ToolHandler> */
    public function allHandlers(): array { return $this->handlers; }

    /** @internal Called by ConnectorApp::agent(). */
    public function __setParentApp(ConnectorApp $app): void
    {
        $this->parentApp = $app;
    }

    public function endAgent(): ConnectorApp
    {
        if ($this->parentApp === null) {
            throw new ConfigException(
                'endAgent() called on AgentBuilder created outside ConnectorApp'
            );
        }
        return $this->parentApp->__closeCurrentAgent();
    }

    /** @return array<string,mixed>  the wire-shape declaration */
    public function toDeclaration(): array
    {
        $seen = [];
        foreach ($this->instructions as $i) {
            if (isset($seen[$i->position])) {
                throw new ConfigException(
                    "duplicate instruction position {$i->position} on agent {$this->key}"
                );
            }
            $seen[$i->position] = true;
        }
        $sorted = $this->instructions;
        usort($sorted, fn (InstructionBuilder $a, InstructionBuilder $b) => $a->position <=> $b->position);

        return [
            'key'          => $this->key,
            'name'         => $this->name === '' ? $this->key : $this->name,
            'description'  => $this->description,
            'status'       => $this->status,
            'model'        => $this->model ?? ['provider' => '', 'name' => '', 'config' => []],
            'instructions' => array_map(fn (InstructionBuilder $i) => $i->toArray(), $sorted),
            'tools'        => $this->tools,
        ];
    }
}
