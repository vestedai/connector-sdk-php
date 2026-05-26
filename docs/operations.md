# Operations

## Docker

The official base image is `vestedai/vested-ai-connector-sdk-php:0.2.x` on Docker Hub. It ships PHP 8.3, Swoole 5.1, and the SDK binary at `/usr/local/bin/vested-connect`.

A minimal customer Dockerfile:

```dockerfile
FROM vestedai/vested-ai-connector-sdk-php:0.2.4

# Copy your bootstrap and source tree
COPY bootstrap.php /app/bootstrap.php
COPY src /app/src
COPY composer.json composer.lock /app/
RUN composer install --no-dev --no-interaction --optimize-autoloader

ENTRYPOINT ["vested-connect", "worker", "--bootstrap=/app/bootstrap.php"]
```

The entrypoint reads `VESTED_CONNECTOR_TOKEN` and `VESTED_CONNECTOR_HUB` from the environment. If neither `--bootstrap` nor `--hub-addr` is given on the CLI, the worker reads them from env vars.

Run as a single long-lived container (`replicas: 1` per token in Kubernetes). Graceful shutdown on SIGTERM: in-flight tool calls drain for up to 30 seconds before the process exits.

---

## Environment Variables

| Variable | Required | Default | Description |
|---|---|---|---|
| `VESTED_CONNECTOR_TOKEN` | Yes | — | JWT from the admin UI (Integrations → Add). Passed via `--token-stdin` for systemd credentials. |
| `VESTED_CONNECTOR_HUB` | Yes | — | Hub address as `host:port`, e.g. `ai-connect.example.com:4443`. |
| `LOG_LEVEL` | No | `info` | PSR-3 level: `debug`, `info`, `warning`, `error`. Only takes effect if your `bootstrap.php` reads it; the SDK itself does not read this variable. |
| `WORKER_POOL_SIZE` | No | `4` | Passed to `ConnectorApp::withWorkerPoolSize()` in your bootstrap if you wire it. The SDK does not read this variable directly. |

For secrets management, `--token-stdin` lets you pipe the token from any credential provider:

```bash
# systemd credential
cat "$CREDENTIALS_DIRECTORY/vested-token" | vested-connect worker --bootstrap=./bootstrap.php --token-stdin

# Vault / AWS SSM / SOPS — same pattern
```

---

## Observability

**Structured log fields** present on every log line emitted by the SDK:

| Field | Present on |
|---|---|
| `connector_id` | All lines after HelloAck |
| `invocation_id` | Tool-call lines |
| `agent_key` | Tool-call lines |
| `tool_key` | Tool-call lines |
| `duration_ms` | Tool-call completion |

**Key log events by level:**

- `info` — `connected to hub` (with `connector_id`, `namespace`, `max_concurrent`); `stream closed`; `drain complete`; `shutdown requested`
- `warning` — `hub session ended, reconnecting` (with `delay_ms`, `handshake_completed`, `last_exit`); `GoAway from hub`
- `error` — `token rejected`; `register issue`; `session ended` (with exception class + message)

The logger passed to `withLogger()` is pre-bound per tool call with `invocation_id`, `agent_key`, and `tool_key` — use `$ctx->logger` inside handlers for correlated output.

**Heartbeat**: the SDK sends a `Heartbeat` frame every 15 seconds. The hub replies with `HeartbeatAck`. No heartbeat acknowledgement within the idle-timeout window (30 s) causes the hub to send `GoAway{idle}`.

**OpenTelemetry (optional)**: if `open-telemetry/sdk` is installed and `ConnectorApp::withTracer($tracer)` is called, the SDK emits spans: `connector.connect`, `connector.register`, `connector.tool_call`, `connector.tool_handler`.

---

## Reconnect + Supervisor

`ConnectorApp::runSwooleDaemon()` embeds a supervisor loop. The lifecycle is:

```
supervisor loop
  └── new Daemon session
        ├── open gRPC stream
        ├── Hello/HelloAck
        ├── Register/RegisterAck  ← handshakeCompleted = true
        ├── steady-state (tool calls + heartbeats)
        └── disconnect / GoAway / error
              ↓
        if signal: exit 0
        if token rejected: exit 78 (EX_CONFIG)
        if handshake completed: reset backoff
        sleep(backoff.next())
        → new Daemon session
```

**Backoff schedule**: 1 s → 2 s → 4 s → 8 s → 16 s → 30 s (cap). Each interval has ±20 % random jitter. A session that completed handshake before disconnecting resets the backoff to 1 s — hub deploys and node maintenance cause fast reconnect. A session that failed before handshake (hub down, network partition) keeps backing off.

SIGTERM arriving during the inter-attempt sleep is caught immediately — the signal handler is installed at the supervisor level, not per session.

Token rotation sends `GoAway{token_rotated}` on the active stream. The daemon exits with code 78. Redeploy with the new token; the supervisor does not retry on exit 78.

---

## Database Connection Pool Sizing

When tool handlers query a database, use a connection pool. Swoole coroutines are concurrent: multiple `handle()` calls run in parallel, and a single shared PDO connection will serialize all queries or produce `Cannot execute queries while other unbuffered queries are active` errors.

Recommended: a raw PDO pool sized to match your concurrency expectations:

```php
// In bootstrap.php — size pool relative to max_concurrent_tool_calls
// (sent in HelloAck; default 16 in the connectors table).
$pool = new MyPdoPool(size: 8, dsn: getenv('DB_DSN'));

ConnectorApp::create()
    ->scanNamespace('MyApp\\Tools', __DIR__ . '/src/Tools', new MyContainer($pool))
    ->build();
```

**Avoid `Swoole\Database\PDOPool` combined with Illuminate/Laravel's PDO layer.** `PDOProxy` wraps the connection in a way that breaks Illuminate's `PDOStatement` type hints at runtime. Use a plain PDO wrapper that hands out real `PDO` instances.

**Return connections in `Coroutine::defer`**, not in `__destruct`. Swoole does not guarantee `__destruct` runs in the same coroutine context that acquired the resource, so pool-return via destructor can race:

```php
public function handle(array $args, ToolContext $ctx): array
{
    $pdo = $this->pool->get();
    \Swoole\Coroutine::defer(fn () => $this->pool->put($pdo));

    // ... use $pdo ...
}
```

---

## Known Gotchas

**Monolog logging-loop guard (fixed in v0.2.3)**
Monolog's loop-detection guard uses a single depth counter keyed to PHP Fibers. Swoole coroutines are not Fibers, so concurrent log calls from parallel tool handlers share the counter and trip the guard with `"A possible infinite logging loop was detected"`. Since v0.2.3, the SDK automatically calls `$logger->useLoggingLoopDetection(false)` when running under Swoole. No code change needed; this is handled in `WorkerCommand::execute()`.

**PDO + Swoole concurrency**
See [Database Connection Pool Sizing](#database-connection-pool-sizing) above. A single shared `PDO` instance is the most common source of runtime errors in Swoole-based connectors.

**`Coroutine::defer` over `__destruct` for pool returns**
Documented above. The rule: anything acquired in a coroutine context should be released via `Coroutine::defer`, not a destructor.

---

## Troubleshooting

**`connector_unavailable`**
The tool dispatch arrived while the connector was disconnected. The hub held the call for up to 5 s waiting for a reconnect. Check `hub session ended, reconnecting` in the connector logs to understand why the session ended. Verify the supervisor is running and not stuck on exit 78.

**`unknown tool runtime=http` with `is_baseline_tool=false`**
The `agent_tools` pivot row for this tool has `is_baseline_tool=false`, so the runtime tried to dispatch it over the legacy PHP path instead of the connector hub. Cause: a manual DB edit or a historic bug created the pivot with the wrong flag. Fix: use the admin UI "Reset to baseline" action on the tool, or re-publish the agent version.

**`Cannot execute queries while other unbuffered queries are active`**
PDO is not pooled; multiple coroutines are sharing a single connection. Add a connection pool (see above).

**Infinite logging loop (`"A possible infinite logging loop was detected"`)**
Running SDK < v0.2.3. Upgrade to 0.2.3+. As a temporary workaround call `$logger->useLoggingLoopDetection(false)` in your bootstrap before passing the logger to `ConnectorApp`.

**`drain_timeout`**
A tool handler exceeded `deadlineMs` (default 30 000 ms). The hub cancelled the call and the handler result was discarded. Either increase `deadlineMs` on the `#[Tool]` attribute / `withTool()` call, or speed up the handler (add a read timeout to your HTTP client, cache expensive lookups, etc.).

## Next

[Upgrading](upgrading.md)
