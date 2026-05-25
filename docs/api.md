# Public API

## `ConnectorApp`

| Method | Returns | Purpose |
|---|---|---|
| `ConnectorApp::create()` | `self` | Static constructor. |
| `->withLogger(LoggerInterface)` | `self` | PSR-3 logger. Default: NullLogger. |
| `->withTracer(object)` | `self` | OpenTelemetry TracerInterface. Optional. |
| `->withWorkerPoolSize(int $n)` | `self` | Default 4. Min 1. Hub's `max_concurrent_tool_calls` may clamp this lower at connect-time. |
| `->agent(string $key)` | `AgentBuilder` | Begin declaring an agent. Chain mutators on the returned AgentBuilder; call `->endAgent()` to return here. |
| `->scanNamespace(string $namespace, string $dir, ?ContainerInterface = null)` | `self` | Discover #[Agent]/#[Tool]-decorated classes. PSR-11 container resolves handler dependencies. |
| `->build()` | `self` | Validate + freeze. Required before `run()`. |
| `->agents()` | `AgentRegistry` | After build(). |
| `->tools()` | `ToolRegistry` | After build(). |

## `AgentBuilder`

| Method | Returns | Purpose |
|---|---|---|
| `->name(string)` | `self` | Display name; defaults to the key. |
| `->description(string)` | `self` | Free-text description. |
| `->status('active'\|'inactive')` | `self` | Default `active`. |
| `->withModel(string $provider, string $name, array $config = [])` | `self` | Provider, model name, free-form config (temperature, max_tokens, …). |
| `->withInstruction(string $body, string $type = 'system', int $position = 0, string $format = 'markdown')` | `self` | Multiple allowed; sorted by position; duplicate positions fail at build(). |
| `->withTool(string $key, string $name, string $description, array $inputSchema, array $outputSchema, Closure\|ToolHandler $handler, int $deadlineMs = 30000, int $maxResultBytes = 1048576)` | `self` | Add a tool to this agent. |
| `->endAgent()` | `ConnectorApp` | Returns the parent ConnectorApp for chaining the next agent. |

## Attributes (class-based API)

- `#[Agent(string $key, string $name, string $description = '', string $status = 'active')]`
- `#[Tool(string $agentKey, string $key, string $name, string $description = '', string $inputSchemaFile = '', string $outputSchemaFile = '', ?array $inputSchema = null, ?array $outputSchema = null, int $deadlineMs = 30000, int $maxResultBytes = 1048576)]`
- `#[Instruction(string $type, int $position, string $body, string $format = 'markdown')]` — repeatable on the same class.
- `#[Model(string $provider, string $name, array $config = [])]`

Class-based tools implement `Vested\Connect\Sdk\Tool\ToolHandler`:

```php
public function handle(array $args, ToolContext $ctx): array;
```

## `ToolContext`

Readonly struct passed to every handler. Fields: `invocationId`,
`organizationId`, `userId`, `userEmail`, `conversationId`, `agentKey`,
`toolKey`, `deadlineMs`, `logger` (pre-bound with `invocation_id`),
`invokedAt`. Helpers: `callerEmailOrNull()`, `isSystemRun()`.
