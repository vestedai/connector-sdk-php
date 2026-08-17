# Vested AI Connector SDK (PHP)

![Build](https://img.shields.io/github/actions/workflow/status/vestedsystems/connector-sdk-php/ci.yml?branch=main)
![Packagist](https://img.shields.io/packagist/v/vested-ai/connector-sdk-php)
![License](https://img.shields.io/github/license/vestedsystems/connector-sdk-php)
![PHP](https://img.shields.io/badge/php-%5E8.3-blue)

Connect any PHP service to the Vested AI platform. The SDK opens a long-lived gRPC stream to the hub, declares agents and tools over that stream, and dispatches tool calls to your handler code — no polling, no webhook setup, no managing your own LLM client. The hub handles model selection, prompt composition, and conversation state; your connector owns the business logic.

## Install

```bash
composer require vested-ai/connector-sdk-php
```

Or pull the pre-built Docker image (PHP 8.3 + Swoole bundled):

```bash
docker pull vestedai/vested-ai-connector-sdk-php:0.8.0
```

## 30-Second Example

The scanner maps **file path to class name**, PSR-4 style, so one class per file
named after it — a class declared in `bootstrap.php` is never discovered.

```php
<?php
// src/Orders/OrdersAgent.php
namespace MyApp\Orders;

use Vested\Connect\Sdk\Attribute\{Agent, Model, Instruction};

#[Agent(key: 'myapp.orders', name: 'Orders')]
#[Model(provider: 'openai', name: 'gpt-4o')]
#[Instruction(type: 'system', position: 0, body: 'You help users look up their orders.')]
class OrdersAgent {}
```

```php
<?php
// src/Orders/GetOrder.php
namespace MyApp\Orders;

use Vested\Connect\Sdk\Attribute\Tool;
use Vested\Connect\Sdk\Tool\{ToolHandler, ToolContext};

#[Tool(
    agentKey:     'myapp.orders',
    key:          'myapp.orders.get',
    name:         'Get order',
    description:  'Returns an order by ID.',
    inputSchema:  ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']],
    outputSchema: ['type' => 'object', 'properties' => ['status' => ['type' => 'string']], 'required' => ['status']],
)]
final class GetOrder implements ToolHandler {
    public function handle(array $args, ToolContext $ctx): array {
        return ['status' => 'shipped'];   // replace with a real lookup
    }
}
```

```php
<?php
// bootstrap.php — must RETURN a ConnectorApp
require_once __DIR__ . '/vendor/autoload.php';

use Vested\Connect\Sdk\ConnectorApp;

return ConnectorApp::create()
    ->scanNamespace('MyApp\\Orders', __DIR__ . '/src/Orders')
    ->build();
```

```bash
VESTED_CONNECTOR_TOKEN=eyJ… VESTED_CONNECTOR_HUB=hub.example.com:4443 \
vendor/bin/vested-connect worker --bootstrap=./bootstrap.php
```

## Declarations

Beyond agents and tools, a connector can declare two optional things on
`Register`. Both follow the same contract: **declare nothing and nothing
changes.** A connector that declares neither is untouched by both features.

**You may declare both, and the combination needs one thing set up on the
platform side.** Declaring a `credential_schema` used to make a connector
permanently un-extractable; that is no longer true, and if you have read that
here before, this paragraph is the correction.

A connector that declares a `credential_schema` is schema-extracted **as a named
person**: the platform holds an automation user for the connector and the hub
resolves that user's sealed credential for the extraction, exactly as it would
for a tool call. Without one, extraction is refused with `403 credential_gated`,
*"connector declares per-user credentials; schema extraction has no acting user
to gate as"* — extraction is system-initiated, so with nobody named there is no
credential to seal for.

Nothing you do in the SDK changes which way that goes: it is an operator setting
on the platform, not a field on `Register`. What you should know is the shape of
the failure if it has not been done. The refusal talks about **credentials** on
the **schema** path and nothing points back at your `Register`, so it reads like
a bug in extraction. The same applies in reverse: putting an already-extracted
connector behind per-user credentials stops its extraction on the day you deploy
it, until an automation user is named. Ask for that to be set up first, and the
gap never opens.

### `#[CredentialSchema]` — per-user credentials

Put `#[CredentialSchema]` on your `UserCredentialHandler`, one
`#[CredentialField]` per field of the form the platform renders. Declaring a
schema is what gates this connector's tools on the calling user having valid
credentials.

```php
<?php
// src/Erp/ErpCredentials.php
namespace MyApp\Erp;

use Vested\Connect\Sdk\Attribute\{CredentialField, CredentialSchema};
use Vested\Connect\Sdk\Credential\{CredentialContext, CredentialValidation, UserCredentialHandler};

#[CredentialSchema(kind: 'basic', title: 'Al-Saif ERP account')]
#[CredentialField(key: 'username', label: 'ERP username', type: 'text',     required: true)]
#[CredentialField(key: 'password', label: 'ERP password', type: 'password', required: true)]
final class ErpCredentials implements UserCredentialHandler
{
    public function __construct(private readonly ErpClient $erp) {}

    public function validate(CredentialContext $ctx, array $credential): CredentialValidation
    {
        $who = $this->erp->whoami($credential['username'], $credential['password']);

        return $who === null
            ? CredentialValidation::failed('ERP rejected those credentials.')
            : CredentialValidation::ok(['account' => $who->login]);
    }

    // Optional: tear down a remote session. Best-effort.
    public function revoke(CredentialContext $ctx, array $credential): void {}
}
```

```php
$app->withCredentialHandler(new ErpCredentials($erp));
```

The handler needs a private key to open sealed envelopes — set
`VESTED_CREDENTIAL_PRIVATE_KEY` (or `..._FILE`), or pass the PEMs as the second
argument. Registering a handler without one throws at startup rather than
failing every credential check later. Full guide:
[Per-user credentials](docs/credentials.md).

### `#[RelationalSource]` — expose a database to schema extraction

Put `#[RelationalSource]` on your `RelationalSchemaProvider`. Declaring one is
what makes the connector's database visible to the platform's schema
extraction; a connector that declares none is never extracted.

```php
<?php
// src/Magento/MagentoSchemaProvider.php
namespace MyApp\Magento;

use PDO;
use Vested\Connect\Sdk\Attribute\RelationalSource;
use Vested\Connect\Sdk\Schema\{CanonicalSchema, RelationalSchemaProvider};

#[RelationalSource(
    engine:       'mysql',
    describeTool: 'magento.describe_schema',  // a ROWSET tool you declare
    queryTool:    'magento.query_sql',        // the free-form SQL tool
    sqlArg:       'sql',                      // its SQL argument, wire-exact
)]
final class MagentoSchemaProvider implements RelationalSchemaProvider
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return string[] the scopes (databases) this source exposes */
    public function scopes(): array { /* … */ }

    public function describe(string $scopeKey): CanonicalSchema { /* … */ }

    public function catalogFingerprint(): string { /* … */ }
}
```

```php
$app->withRelationalSchemaProvider(new MagentoSchemaProvider($pdo));
```

Four things worth knowing:

- **The declaration is cross-checked.** Both tool keys must name tools this
  connector declares, and `sqlArg` must match an argument of the query tool
  **exactly, including case**. Nothing downstream catches a typo: the platform
  would govern a key nothing answers to while the real tool ran ungoverned,
  which is why it is refused at startup instead.
- **The describe tool must extend `PaginatedToolHandler`.** A catalog does not
  fit one response, and only a paginated handler declares
  `result_kind = rowset`.
- **`catalogFingerprint()` must detect column-level change**, not just
  table-level. Hashing table names alone misses a field added to an existing
  table — the normal shape of a backward-compatible deploy — and would leave the
  platform believing the schema is unchanged. It is called live on every
  `Register`, so there is no fingerprint to supply by hand.
- No PHP connector implements a provider yet. The interface is here so the
  contract is identical across languages; the first implementation will be
  MySQL for Magento.

#### Multiple scopes need a `defaultScope`

`scopes()` can expose more than one database/company (a Magento connector
spanning "production" and "erp_middleware_production", or a Business Central
connector spanning several companies). When it does, an **unqualified** table
name in a query is ambiguous — the platform cannot guess which scope it
belongs to — so `#[RelationalSource]` takes a `defaultScope`:

```php
#[RelationalSource(
    engine:       'mysql',
    describeTool: 'erp.describe_schema',
    queryTool:    'erp.query_sql',
    sqlArg:       'sql',
    defaultScope: 'production',
)]
final class ErpSchemaProvider implements RelationalSchemaProvider
{
    public function scopes(): array
    {
        return ['production', 'erp_middleware_production'];
    }

    // …
}
```

Two invariants are enforced at bootstrap — on `ConnectorApp::build()`, before
the worker ever dials the hub — not at query time:

- **More than one scope with no `defaultScope`** throws `InvalidArgumentException`.
  A single-scope (or scope-less) source may leave `defaultScope` blank.
- **A `defaultScope` naming something `scopes()` never returns** throws
  `InvalidArgumentException` too.

**`scopes()` runs synchronously, inline, during that same bootstrap — before
the worker ever dials the hub.** There is no async variant and no timeout
around it. Keep it cheap and I/O-free: return a declared/constant list (or
one already held in memory), never a live catalog query. A database round
trip in there blocks worker startup on that query, and a slow or unreachable
database delays or fails the boot — worse than the stale-schema risk
`catalogFingerprint()`'s live read exists to avoid. If you need a live,
per-deployment scope list, enumerate it elsewhere (in `describe()`, or your
own warm-up path) — not in `scopes()`.

This is deliberately the same failure shape as the missing-credential-key
check above: refuse on the connector author's own deploy, with a message that
names the fix, rather than let a model's query resolve an unqualified table
name against the wrong database in production.

`defaultScope` decides **only** what an unqualified name means, and nothing
else: a **qualified** `scope.table` reference is never re-pointed at the
default, and a query joining across two scopes is unaffected by it — each
side of the join still resolves in its own scope.

## What This Is

A **connector** is a long-lived worker process that registers one or more agents with the Vested AI hub. Each agent carries a model selection, a set of instruction blocks, and a set of tool definitions. Admins can override instruction bodies and disable tools in the admin UI; the connector's declared baseline is the floor that overrides are layered on top of. The hub routes LLM tool calls back to the connector over the same stream; the connector dispatches them to your handler code and returns results.

This differs from writing your own LLM client. The connector does not call the LLM directly. It registers capability and responds to callbacks. Prompt composition, model routing, conversation history, streaming to end users — all of that lives in the hub. The connector's surface area is: "declare what agents exist, implement what the tools do."

## Documentation

| Document | What's in it |
|---|---|
| [Quickstart](docs/quickstart.md) | Install, write your first agent + tool, run the worker, verify in the admin UI |
| [Concepts](docs/concepts.md) | Agents, tools, instructions, baselines vs overrides, inheritance state machine, reconciliation |
| [API reference](docs/api.md) | `ConnectorApp`, `AgentBuilder`, attributes, `ToolHandler`, `ToolContext` |
| [Operations](docs/operations.md) | Docker, env vars, observability, reconnect supervisor, DB pool sizing, gotchas |
| [Upgrading](docs/upgrading.md) | v0.1 → v0.2 migration; v0.2.x patch notes |
| [Per-user credentials](docs/credentials.md) | Act on behalf of the calling user: sealed credentials the platform cannot read, validation, key rotation |
| [Doc index](docs/README.md) | Full table of contents including protocol reference |

## License + Status

MIT. Current release: **v0.8.0** (Swoole runtime, supervisor reconnect, PDO pool guidance, connector-declared tool sensitivity, `#[CredentialSchema]` and `#[RelationalSource]` Register declarations, `#[RelationalSource]` scopes/defaultScope with a bootstrap throw). Production-ready; used in the alsaif Magento connector.

## Other language SDKs

Same wire protocol, same hub — [all four SDKs](../README.md) are at feature parity (including connector-declared tool sensitivity):

- [Python](../python/README.md) — PyPI `vested-connect-sdk`
- [Node.js](../node/README.md) — npm `@vested-ai/connector-sdk`
- [C# / .NET](../dotnet/README.md) — NuGet `VestedAI.ConnectorSdk`
