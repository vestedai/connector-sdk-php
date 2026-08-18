# Upgrading

> **Other language SDKs:** the connector SDK also ships for [Python](https://pypi.org/project/vested-connect-sdk/) (`vested-connect-sdk`), [Node.js](https://www.npmjs.com/package/@vested-ai/connector-sdk) (`@vested-ai/connector-sdk`), and [C# / .NET](https://www.nuget.org/packages/VestedAI.ConnectorSdk) (`VestedAI.ConnectorSdk`) — all at wire parity, including connector-declared tool sensitivity. See the [SDK index](../../README.md).

## v0.1 → v0.2 (Swoole rewrite)

v0.2 replaces the gRPC-PHP extension with a Swoole-native gRPC client. The wire protocol is identical; the PHP runtime changed.

### composer.json

```diff
 "require": {
-    "ext-grpc": "*",
+    "ext-swoole": "^5.1 || ^6.0",
-    "vested-ai/connector-sdk-php": "^0.1",
+    "vested-ai/connector-sdk-php": "^0.2",
 }
```

Run `composer update vested-ai/connector-sdk-php`.

### Docker base image

```diff
-FROM grpc/php:1.60-php8.3-alpine
+FROM vestedai/vested-ai-connector-sdk-php:0.2.4
```

The new base image bundles PHP 8.3, Swoole 5.1, and the SDK binary. No additional extension installs needed.

### Removed namespaces

`Vested\Connect\Sdk\HubClient` and `Vested\Connect\Sdk\ParentProcess` no longer exist. Remove any imports from these namespaces. Nothing needs to replace them — the SDK internals handle the connection.

### ToolHandler — no changes

The `ToolHandler` interface signature is unchanged:

```php
public function handle(array $args, ToolContext $ctx): array;
```

Tool code requires no edits.

### bootstrap.php — no changes

`ConnectorApp::create()->scanNamespace(...)->build()` works identically. If your v0.1 bootstrap called `$app->runDaemon(...)`, rename it to `$app->runSwooleDaemon(...)`.

### Monolog — no special handling needed

In v0.1 you may have manually disabled Monolog's loop detection. In v0.2 the SDK handles this automatically (see [v0.2.3 notes](#v023--monolog-loop-detection-disabled-under-swoole) below). Remove any `useLoggingLoopDetection(false)` call from your bootstrap — it is harmless to leave it, but unnecessary.

### Behavior changes

- **Automatic reconnect**: the daemon no longer exits on hub disconnect. It backs off and reconnects. Expect `"hub session ended, reconnecting"` log lines on transient disconnects — this is expected behavior, not an error.
- **Single-process, coroutine-concurrent**: no forked workers. All concurrency comes from Swoole coroutines. If your tools spawn child processes or use `pcntl_fork()`, those calls will conflict with the coroutine scheduler — replace them with async equivalents.
- **SIGTERM handling**: the v0.2 supervisor catches SIGTERM during the inter-attempt backoff sleep. Kubernetes graceful-stop windows are respected without needing `terminationGracePeriodSeconds` tuning.

---

## v0.11.0 Release Notes

### v0.11.0 — `ParameterizedSql`: bind-parameter VALUES, and the input-schema fragment authors need

A connector's `run_sql`-style tool could only ever hard-code its window into
the SQL text — nothing carried a caller-supplied value safely to a WHERE
clause. This release ships `Vested\Connect\Sdk\Tool\ParameterizedSql`, a
class of **static methods, not a base class** (both shipped SQL tools already
extend `PaginatedToolHandler`, and PHP is single-inheritance). Pairs with
Task 5's `#[RelationalSource(paramsArg: '...')]` declaration.

```php
public static function normalise(array $params): array;
public static function inputSchemaFragment(string $paramsArg): array;
```

**`normalise()` turns caller-supplied values into something a driver's own
bind call accepts — nothing here ever sees, builds, or returns SQL text:**

- A scalar (or `null`) passes through **unchanged** — including a value that
  is itself SQL text (a quote, a `--` comment, a `DROP TABLE`). Nothing here
  sanitises, escapes, or reinterprets it; the driver's own bind is what makes
  the value inert, once your code passes it through.
- A list becomes **ONE** parameter: a single JSON-encoded string, never
  expanded into `(:p0, :p1, …)` placeholders and never written into the SQL
  string. Read it back out **inside the database**, via `JSON_TABLE`
  (MySQL 8.0.4+) or `OPENJSON` (SQL Server) — see the class docblock for the
  exact SQL shape.
- Anything else — most commonly a nested associative array — is **refused**
  with `InvalidArgumentException` naming the parameter, rather than silently
  substituted or dropped.

Bind the normalised values through your driver's own named-parameter API
(PDO, etc.). This class never opens a connection.

**`inputSchemaFragment(string $paramsArg): array` is the canonical schema for
your params argument**, keyed by whatever name you pass (matching
`paramsArg` on `#[RelationalSource]`). The hub validates a tool call's
arguments against its input schema **before the connector is ever reached** —
a hand-written `additionalProperties: false` schema with no `<paramsArg>`
property silently rejects every parameterized call, and the failure looks
exactly like the connector is broken. Two ways to adopt it:

- `#[Tool(inputSchema: [...])]` — merge the returned entry straight into your
  own `properties` array.
- `#[Tool(inputSchemaFile: ...)]` (a hand-written JSON file, e.g. the live
  ecommerce connector) — keep your `properties.<paramsArg>` copy **equal** to
  `inputSchemaFragment($paramsArg)[$paramsArg]`. A later task (ecommerce
  adoption) asserts that equality directly, so the SDK method is the single
  source of truth and the JSON file is a copy of it, never the other way
  round.

**Additive.** A connector that never calls either method, and never declares
`paramsArg`, is completely unaffected.

Intended git tag: `v0.11.0`.

---

## v0.10.0 Release Notes

### v0.10.0 — the core's resolved tables, exposed read-only on `ToolContext`

The runtime now sends a `SchemaContext` on `ToolCallRequest` (proto field 16)
naming the tables its SQL gate resolved for a governed `run_sql` call. This
release exposes it on `ToolContext` so a connector handler can apply its own
permission layer on top of the core's decision.

```php
public ?\Vested\Connect\Sdk\Tool\SchemaContext $schemaContext = null,
```

Appended **last** in `ToolContext`'s constructor, so every existing
positional and named construction keeps working unchanged.

`SchemaContext` (`Vested\Connect\Sdk\Tool\SchemaContext`) carries:

| Field | Type | Meaning |
|---|---|---|
| `$tables` | `list<SchemaContextTable>` | tables/views the gate resolved — see the `observe` warning below |
| `$hasStar` | `bool` | the statement selects `*` somewhere |
| `$gateMode` | `string` | `"enforce"` \| `"observe"` — which mode the CONNECTOR's gate is configured in. It does **not** say whether the core blocked on this particular call: `$gateMode` reads exactly `"observe"` on a genuine allow AND on a refusal the call is proceeding through alike. Nothing on `SchemaContext` distinguishes the two. |

Each `SchemaContextTable` carries `$logicalName`, `$scope`, `$kind`
(`"table"` \| `"view"`), and `$physical` (`list<string>`, the canonical
name(s) this entity resolved to — key any permission check on this, not on
`$logicalName`).

**Additive. A connector that never reads `$schemaContext` is completely
unaffected** — nothing about dispatch, validation, or existing tool calls
changes.

**⚠ NULL IS NOT AN EMPTY TABLE LIST.** `$schemaContext === null` means the
core sent none: this connector declares no relational source, its gate is
`off`, this is not the governed query tool, or (added 2026-08-18) the gate's
own refusal reason was `parse_failed` or `lookup_failed` — the gate never got
to read the statement at all in either case. It never means "no tables were
touched." A present `SchemaContext` with an empty `tables` array is a
different claim — the gate genuinely decided and resolved nothing (e.g. a
catalog-only read). A handler that treats null as empty builds a permission
check that silently approves everything.

**⚠ IN `"observe"`, `$tables` IS NOT THE COMPLETE SET OF OBJECTS THE
STATEMENT TOUCHES.** A table the core's per-entity check refused is excluded
from this list, but in `observe` the call proceeds and reads it anyway — so a
statement joining a denied table alongside granted ones arrives with
`$tables` missing exactly the table the core flagged. Treat `$tables` as
"every object the core is willing to vouch for," never as "every object the
statement reads."

Intended git tag: `v0.10.0`.

---

## v0.9.0 Release Notes

### v0.9.0 — a tool can declare the agents it binds to

Tools bind to agents by namespace today: `myns.orders.get` belongs to agent `myns.orders` and nowhere else. Sharing behaviour across agents therefore meant duplicating the handler — a second class in a second namespace wrapping the same logic.

A tool can now name the agents it binds to.

```php
#[Tool(agentKey: ['erp.data', 'erp.retail'], key: 'erp.data.run_sql', name: 'Run SQL')]
```

`agentKey` widens from `string` to `string|array`; a plain string keeps working. For the builder API, `withTool()` hangs off a single agent, so sharing has its own entry point — `ConnectorApp::withSharedTool(key:, agents:, …)`. Note its `'*'` resolves immediately and therefore means every agent declared *before* the call, unlike the attribute form which resolves after the whole scan.

**Omitting it changes nothing.** A connector that never sets it binds exactly the tools it binds today.

**A present list is authoritative, not additive.** The key's namespace confers nothing once a list is present, so a tool may live in one namespace and be callable only from another. `'*'` means every agent this connector declares and cannot be combined with explicit keys.

Refused at scan time: an agent key this connector does not declare, `'*'` mixed with explicit keys, and an empty list.

### v0.9.0 — `ToolRegistry` no longer refuses a shared tool

`ToolRegistry::fromAgents()` threw `duplicate tool_key '…' across agents` for any repeated key. That guard also forbade the legitimate case this release enables.

It is narrowed, not removed: **two different handlers** under one key still throw, because dispatch resolves by tool key alone and cannot tell them apart. One handler bound to several agents is the shared tool.

**No fingerprint change in this SDK.** PHP hashes `json_encode()` of the agent declarations and `AgentBuilder::toDeclaration()` nests each agent's tools inside it, so expanding a shared tool into every bound agent moves the fingerprint and the Register frame together. The .NET/Node/Python fingerprint work in this release does not apply here.

Intended git tag: `v0.9.0` (on the public mirror repo).

---

## v0.2.x Patch Notes

### v0.2.0 — Initial Swoole release

Complete rewrite of the runtime from gRPC-PHP extension to Swoole coroutine-native gRPC. Wire protocol unchanged. Not published to Packagist or Docker Hub; used internally.

### v0.2.1 — Initial public release

Published to Packagist (`vested-ai/connector-sdk-php:0.2.1`) and Docker Hub (`vestedai/vested-ai-connector-sdk-php:0.2.1`). First version available to external integrators.

### v0.2.2 — `ETIMEDOUT` recv() fix

`GrpcClient::recv()` previously treated `ETIMEDOUT` from `http2->read()` as a stream close, causing the daemon to exit and trigger an immediate reconnect loop under high-latency network conditions. Fixed: `ETIMEDOUT` is now treated as a read timeout (returns `null`), and the steady-state loop continues.

### v0.2.3 — Monolog loop detection disabled under Swoole

Monolog's depth-based loop-detection counter is keyed to PHP Fibers, not Swoole coroutines. Concurrent tool calls from parallel coroutines share the counter and trip the `depth=3` guard. `WorkerCommand` now calls `$logger->useLoggingLoopDetection(false)` after loading the bootstrap. No code changes needed in connector code.

### v0.4.0 — ERP identity on ToolContext

`ToolContext` gains three new readonly fields carrying the calling user's ERP/HR identity, populated from the incoming `ToolCallRequest` (proto fields 10–12):

| Field | Type | Source proto field |
|---|---|---|
| `$employeeNo` | `string` | `employee_no = 10` |
| `$erpIdentifier` | `string` | `erp_identifier = 11` |
| `$erpDepartmentIdentifiers` | `list<string>` | `erp_department_identifiers = 12` |

All three default to `''` / `[]` when unset (nullable by convention — no null type needed). No changes to `ToolHandler` or `ConnectorApp`. Intended git tag: `v0.4.0`.

### v0.3.0 — Connector-declared tool sensitivity

`#[Tool]` and `AgentBuilder::withTool()` gain an optional `sensitivity` parameter (`read` | `write` | `destructive` | `external_call` | `medium`). Empty (the default) means unset — the hub defaults to `external_call`; admins can override later. An invalid non-empty value throws `ConfigException` at build time. Threaded into the wire `ToolDecl` (proto field 8) and included in the baseline fingerprint (a sensitivity change produces a new fingerprint). Intended git tag: `v0.3.0`.

### v0.2.4 — Reconnect-with-backoff supervisor

`ConnectorApp::runSwooleDaemon()` gained a supervisor loop wrapping the per-session `Daemon`. Previously a single session exit (hub deploy, node restart) would cause the worker process to exit and rely on the pod restarter (5–15 s gap, CrashLoopBackOff risk). Now the supervisor reconnects in-process with exponential backoff (1 s → 30 s cap, ±20 % jitter), resetting on successful handshake. The SIGTERM handler is installed at the supervisor level so it catches signals during backoff sleep.

---

## v0.8.x Patch Notes

### v0.8.0 — `#[RelationalSource]` gains `defaultScope`, and `scopes()` is now wired onto the declaration — SOURCE-BREAKING AT BOOTSTRAP

Two declaration fields now name which databases/companies a connector's relational source spans and, when it spans more than one, which one an unqualified table name resolves in:

| Field | Type | Default | Meaning |
|---|---|---|---|
| `scopes` | `list<string>` | read from `RelationalSchemaProvider::scopes()` (an interface method that already existed) | The databases/companies this source spans. Previously declared on the wire but never populated from PHP; `DeclarationFactory::relationalSourceFrom()` now actually calls `scopes()` and carries the result through. Empty for a scope-less (single-database) source. |
| `defaultScope` | `string` | `''` | **New** constructor parameter on `#[RelationalSource]`, appended last so existing positional and named usages still compile. Which of `scopes()` an unqualified table name resolves in. |

**`ConnectorApp::build()` can now throw where it previously could not.** `DeclarationFactory::relationalSourceFrom()` validates two invariants at build time, before the worker ever dials the hub, and throws `InvalidArgumentException` (not this file's usual `ConfigException` — the mistake is a bad VALUE relationship between two fields the author supplied, not a missing declaration):

1. `count(scopes) > 1 && defaultScope === ''` — a source spanning more than one scope must name a default; a `RelationalSource` type that used to build cleanly now fails at bootstrap if it declares two or more scopes with no `defaultScope`.
2. `count(scopes) > 1 && defaultScope !== '' && !in_array(defaultScope, scopes)` — with SEVERAL scopes, a named default must be one of them.

**With exactly one scope, `defaultScope` is ignored and the sole scope is declared instead** — whatever the attribute says, including nothing. `scopes()` is runtime data (a DSN's database name) while `defaultScope` is a compile-time constant, so a connector that hardcodes its production database would otherwise fail to boot in every environment whose database is named something else — its own test suite included. Emitting the real scope keeps the declaration true everywhere, and with one scope there is nothing to disambiguate, so no information is lost. The visible consequence: a single-scope source that declares no default now ships `default_scope = <that scope>` rather than `''`.

Same seam and same reasoning as the existing credential-keyring check: refuse on the connector author's own deploy, with a stack trace, rather than let an unqualified table name resolve ambiguously the first time a model calls the query tool in production. A connector declaring neither field is completely unaffected — `scopes` comes back empty, `defaultScope` comes back `''`, and neither check can fire.

**Why this is a minor bump and not a patch.** A `RelationalSource` provider already spanning more than one scope, built against v0.7.x, compiles and runs unchanged today; under v0.8.0 it throws at its next `build()` until its author adds a `defaultScope`. That is a startup-time behavior change with no source signature change in PHP (unlike the .NET SDK — see its own v0.6.0 notes — PHP's `scopes()`/`defaultScope` are read via `RelationalSchemaProvider`/`#[RelationalSource]`, not positional constructor parameters, so nothing here fails to *compile*).

No other API changed. Intended git tag: `v0.8.0`.

## Next

[Connector protocol overview](protocol/overview.md)
