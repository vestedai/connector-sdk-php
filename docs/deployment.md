# Deployment

## Docker

The official image is `vested-ai-sdks/php:latest`. It expects:

| Mount | Purpose |
|---|---|
| `-v ./bootstrap.php:/app/bootstrap.php:ro` | Your ConnectorApp definition |
| `-v ./src:/app/src:ro` | If using class-based style, mount your tool classes |

| Env var | Purpose |
|---|---|
| `VESTED_CONNECTOR_TOKEN` | The JWT from the admin UI. Required. |
| `VESTED_CONNECTOR_HUB` | Override hub address. Default `ai-connect.alsaifgallery.com:4443`. |

Run as a long-lived service (docker-compose / k8s deployment). Graceful
shutdown on SIGTERM with 30s drain.

## systemd

```ini
# /etc/systemd/system/vested-connect.service
[Unit]
Description=Vested AI Connector
After=network-online.target

[Service]
Type=simple
ExecStart=/opt/connector/vendor/bin/vested-connect worker --bootstrap=/opt/connector/bootstrap.php
EnvironmentFile=/etc/vested-connect/env
Restart=on-failure
RestartSec=5
# Don't auto-restart on EX_CONFIG (78) — token revoked/rotated needs operator attention
RestartPreventExitStatus=78
User=connector
Group=connector
KillSignal=SIGTERM
TimeoutStopSec=35

[Install]
WantedBy=multi-user.target
```

`/etc/vested-connect/env` (0600 root:root, NOT world-readable):

```
VESTED_CONNECTOR_TOKEN=eyJ...
```

### systemd credentials (avoiding the token in env)

If you'd rather not put the token in an env file at all, pipe it through
[systemd credentials](https://systemd.io/CREDENTIALS/) using
`--token-stdin`. The credential is mounted as a file with the unit
running as the credentialed user only; nothing leaks into `/proc/$pid/environ`:

```ini
[Service]
LoadCredential=vested-connector-token:/etc/vested-connect/token
ExecStart=/bin/sh -c 'cat "$CREDENTIALS_DIRECTORY/vested-connector-token" | /opt/connector/vendor/bin/vested-connect worker --bootstrap=/opt/connector/bootstrap.php --token-stdin'
```

The flag works with anything that can pipe to stdin — Vault agent, SOPS
decrypt, AWS Secrets Manager via `aws ssm`, etc. The daemon reads one
line and discards stdin afterwards.

## Kubernetes

A `Deployment` with `replicas: 1` per token (each connector = one
exclusive hub stream). Use a `Secret` for the token, mount via env. See
the SDK's `docker/Dockerfile` and adapt to your cluster's conventions.

## Logging

The SDK uses PSR-3. Plug in Monolog, Symfony Logger, or any other
implementation via `ConnectorApp::withLogger()`. By default the daemon
is quiet (NullLogger).

## Tracing (optional)

If `open-telemetry/sdk` is installed and you call
`ConnectorApp::withTracer($tracer)`, the SDK emits spans:
`connector.connect`, `connector.register`, `connector.tool_call`,
`connector.tool_handler`.

## Process model

When `vested-connect worker` is running, you'll see **N+2 processes**:

- **1 parent** — the daemon orchestrator. Owns the worker pool, the
  event loop, signal handling. Does NOT speak gRPC directly.
- **1 stream-reader child** — forked off the parent before the worker
  pool. Owns the bidi gRPC stream to the hub and bridges it to the
  parent via a Unix-socket pipe (length-prefixed protobuf frames).
- **N worker children** — one per concurrent tool call, configured via
  `ConnectorApp::withWorkerPoolSize($n)`. The hub can lower this at
  HelloAck time via `max_concurrent_tool_calls`; if so the parent
  downsizes the pool and logs a warning.

The reader exists because ext-grpc's `BidiStreamingCall::read()` blocks
inside libgrpc and can't be interrupted by PHP signals or selected on.
Hoisting the stream into its own process lets the parent
`stream_select()` over its worker sockets + the reader pipe instead of
stalling behind each blocking read. On hub disconnect the reader
writes an empty-body `HubMsg` sentinel and exits; the parent treats
that as a reconnect signal and re-forks a fresh reader after the
exponential-backoff delay.

If you see fewer than `N+2` processes after a few seconds, check the
logs — both the reader (on hub disconnect) and workers (on crash)
auto-respawn, but a wedged respawn loop is worth investigating.
