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

## v0.2.x Patch Notes

### v0.2.0 — Initial Swoole release

Complete rewrite of the runtime from gRPC-PHP extension to Swoole coroutine-native gRPC. Wire protocol unchanged. Not published to Packagist or Docker Hub; used internally.

### v0.2.1 — Initial public release

Published to Packagist (`vested-ai/connector-sdk-php:0.2.1`) and Docker Hub (`vestedai/vested-ai-connector-sdk-php:0.2.1`). First version available to external integrators.

### v0.2.2 — `ETIMEDOUT` recv() fix

`GrpcClient::recv()` previously treated `ETIMEDOUT` from `http2->read()` as a stream close, causing the daemon to exit and trigger an immediate reconnect loop under high-latency network conditions. Fixed: `ETIMEDOUT` is now treated as a read timeout (returns `null`), and the steady-state loop continues.

### v0.2.3 — Monolog loop detection disabled under Swoole

Monolog's depth-based loop-detection counter is keyed to PHP Fibers, not Swoole coroutines. Concurrent tool calls from parallel coroutines share the counter and trip the `depth=3` guard. `WorkerCommand` now calls `$logger->useLoggingLoopDetection(false)` after loading the bootstrap. No code changes needed in connector code.

### v0.3.0 — Connector-declared tool sensitivity

`#[Tool]` and `AgentBuilder::withTool()` gain an optional `sensitivity` parameter (`read` | `write` | `destructive` | `external_call` | `medium`). Empty (the default) means unset — the hub defaults to `external_call`; admins can override later. An invalid non-empty value throws `ConfigException` at build time. Threaded into the wire `ToolDecl` (proto field 8) and included in the baseline fingerprint (a sensitivity change produces a new fingerprint). Intended git tag: `v0.3.0`.

### v0.2.4 — Reconnect-with-backoff supervisor

`ConnectorApp::runSwooleDaemon()` gained a supervisor loop wrapping the per-session `Daemon`. Previously a single session exit (hub deploy, node restart) would cause the worker process to exit and rely on the pod restarter (5–15 s gap, CrashLoopBackOff risk). Now the supervisor reconnects in-process with exponential backoff (1 s → 30 s cap, ±20 % jitter), resetting on successful handshake. The SIGTERM handler is installed at the supervisor level so it catches signals during backoff sleep.

## Next

[Connector protocol overview](protocol/overview.md)
