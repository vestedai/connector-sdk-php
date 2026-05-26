# API Reference

## ConnectorApp

The top-level facade. Build one in `bootstrap.php` and return it; the CLI loads and runs it.

Source: `src/Vested/Connect/Sdk/ConnectorApp.php`

**`ConnectorApp::create(): self`**
Static constructor. All configuration follows via chained calls.

```php
$app = ConnectorApp::create();
```

**`->withLogger(LoggerInterface $logger): self`**
Plug in any PSR-3 logger (Monolog, Symfony Logger, etc.). Default: `NullLogger` (silent).

```php
$app->withLogger(new \Monolog\Logger('connector'));
```

**`->withWorkerPoolSize(int $n): self`**
Number of concurrent tool-call coroutines. Default: 4. Minimum: 1. The hub's `max_concurrent_tool_calls` value (sent in `HelloAck`) is a separate cap — size your pool relative to that number.

**`->agent(string $key): AgentBuilder`**
Begin declaring an agent imperatively. Chain builder methods on the returned `AgentBuilder`, then call `->endAgent()` to return to `ConnectorApp`.

```php
$app->agent('myns.orders')
    ->withModel('openai', 'gpt-4o')
    ->withInstruction('You manage order data.', type: 'system', position: 0)
    ->withTool('myns.orders.get', 'Get order', '...', $inputSchema, $outputSchema, $handler)
    ->endAgent()
    ->build();
```

**`->scanNamespace(string $namespace, string $dir, ?ContainerInterface $container = null): self`**
Discover `#[Agent]`- and `#[Tool]`-decorated classes in `$dir`. If a PSR-11 container is provided, it resolves tool handler constructor dependencies automatically.

```php
$app->scanNamespace('MyApp\\Agents', __DIR__ . '/src/Agents')
    ->scanNamespace('MyApp\\Tools',  __DIR__ . '/src/Tools', $container);
```

**`->build(): self`**
Validate and freeze the declared agents and tools. Must be called before `runSwooleDaemon()`. Throws `ConfigException` on duplicate keys, duplicate instruction positions, or missing required fields.

**`->runSwooleDaemon(string $token, string $hubAddr, bool $insecure = false): int`**
Run the supervisor loop. Connects to the hub, sends Hello+Register, then enters steady-state. On disconnect, backs off and reconnects. Returns 0 on clean shutdown (SIGTERM/SIGINT), 78 on token rejection (`EX_CONFIG`). `$insecure = true` uses plaintext gRPC — for local dev only.

---

## AgentBuilder

Returned by `ConnectorApp::agent($key)`. Every method returns `self` for chaining except `endAgent()`.

Source: `src/Vested/Connect/Sdk/Agent/AgentBuilder.php`

**`->name(string $name): self`** — Display name. Defaults to the key.

**`->description(string $description): self`** — Free-text description shown in the admin UI.

**`->status('active'|'inactive'): self`** — Default `active`. Set `inactive` to register the agent but keep it from receiving calls.

**`->withModel(string $provider, string $name, array $config = []): self`**
Provider + model name + optional config (temperature, max_tokens, etc.).

```php
->withModel('openai', 'gpt-4o', ['temperature' => 0.2])
```

**`->withInstruction(string $body, string $type = 'system', int $position = 0, string $format = 'markdown'): self`**
Add an instruction block. Types: `system`, `task`, `persona`, `safety`. Duplicate positions throw `ConfigException` at `build()`.

**`->withTool(string $key, string $name, string $description, array $inputSchema, array $outputSchema, Closure|ToolHandler $handler, int $deadlineMs = 30000, int $maxResultBytes = 1048576): self`**
Register a tool on this agent. `$inputSchema` and `$outputSchema` are JSON Schema arrays. The hub validates args against input schema before dispatching and result against output schema after.

**`->endAgent(): ConnectorApp`**
Close this builder and return the parent `ConnectorApp` for chaining the next agent.

---

## Attributes

Use these on PHP classes instead of the builder API. Mix and match — scan one namespace for agents, another for tools.

Source: `src/Vested/Connect/Sdk/Attribute/`

### `#[Agent]`

```php
#[Agent(
    key:         'myns.orders',
    name:        'Orders',
    description: 'Manages order data',
    status:      'active',          // default
)]
class OrdersAgent {}
```

Applied to a class. The class body is unused — it is a declaration container only.

### `#[Model]`

```php
#[Model(provider: 'openai', name: 'gpt-4o', config: ['temperature' => 0.2])]
class OrdersAgent {}
```

Applied to the same class as `#[Agent]`. One per class.

### `#[Instruction]`

```php
#[Instruction(type: 'system',  position: 0, body: 'You manage order data.')]
#[Instruction(type: 'persona', position: 1, body: 'Professional, concise.')]
class OrdersAgent {}
```

Repeatable on the same class. `format` defaults to `markdown`.

### `#[Tool]`

```php
#[Tool(
    agentKey:         'myns.orders',
    key:              'myns.orders.get',
    name:             'Get order',
    description:      'Returns a single order by ID.',
    inputSchemaFile:  __DIR__ . '/schemas/get_order.input.json',   // path to JSON file
    outputSchemaFile: __DIR__ . '/schemas/get_order.output.json',
    deadlineMs:       5000,
    maxResultBytes:   65536,
)]
final class GetOrder implements ToolHandler { ... }
```

`inputSchemaFile` / `outputSchemaFile` load JSON Schema from a file path (resolved at scan time). Alternatively pass `inputSchema: [...]` / `outputSchema: [...]` as inline arrays.

---

## ToolHandler Interface

Source: `src/Vested/Connect/Sdk/Tool/ToolHandler.php`

```php
interface ToolHandler
{
    public function handle(array $args, ToolContext $ctx): array;
}
```

`$args` — decoded args JSON, already validated against the tool's input schema.
Return value — plain PHP array; validated against output schema before reaching the LLM.

Throw any `\Throwable` to signal a handler error. The hub converts it to a `ToolCallResponse{error: ...}` and surfaces it in the run timeline.

---

## ToolContext

Source: `src/Vested/Connect/Sdk/Tool/ToolContext.php`

Readonly value object passed to every handler.

| Field | Type | Description |
|---|---|---|
| `$invocationId` | `string` | Hub-minted UUIDv7. Stable across logs and traces. |
| `$organizationId` | `string` | Org that owns this run. |
| `$userId` | `string` | User who triggered the run. Empty for system/scheduled runs. |
| `$userEmail` | `string` | Caller's email. Empty for system runs. **PII — do not log or persist.** |
| `$conversationId` | `string` | Conversation this run belongs to. |
| `$agentKey` | `string` | Key of the agent being run. |
| `$toolKey` | `string` | Key of this tool. |
| `$deadlineMs` | `int` | Remaining deadline in ms. Handler should respect this. |
| `$logger` | `LoggerInterface` | Pre-bound with `invocation_id`, `agent_key`, `tool_key`. |
| `$invokedAt` | `DateTimeImmutable` | Wall-clock time the hub dispatched the call. |

Helpers:

```php
$ctx->callerEmailOrNull();  // returns null for system runs
$ctx->isSystemRun();        // true when userId === ''
```

---

## Container Integration

`scanNamespace()` accepts an optional PSR-11 `ContainerInterface`. When provided, the scanner resolves each `ToolHandler` class through the container — constructor dependencies (database connections, HTTP clients, etc.) are injected automatically.

```php
use Psr\Container\ContainerInterface;

$container = /* your DI container */;

ConnectorApp::create()
    ->scanNamespace('MyApp\\Tools', __DIR__ . '/src/Tools', $container)
    ->build();
```

Without a container, the scanner instantiates tool classes with `new $class()` — dependencies must have default constructors.

## Next

[Operations](operations.md)
