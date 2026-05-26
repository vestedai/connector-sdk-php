# Deployment

## Docker

The official image is `vestedai/vested-ai-connector-sdk-php:latest` on Docker Hub. It expects:

| Mount | Purpose |
|---|---|
| `-v ./bootstrap.php:/app/bootstrap.php:ro` | Your ConnectorApp definition |
| `-v ./src:/app/src:ro` | If using class-based style, mount your tool classes |

| Env var | Purpose |
|---|---|
| `VESTED_CONNECTOR_TOKEN` | The JWT from the admin UI. Required. |
| `VESTED_CONNECTOR_HUB` | **Required.** The hub address as `host:port` (or pass `--hub-addr`). The daemon refuses to start if neither is set. |

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

## Process model (v0.2 — Swoole)

The daemon runs as a SINGLE PHP process. No forks, no worker pool of
children. Concurrency comes from Swoole coroutines:

- 1 PID, 1 PHP process
- 1 main coroutine owns the gRPC stream
- N per-call coroutines spawn on each ToolCallRequest (no upper bound by
  default; cap with `WORKER_POOL_SIZE` env if you want backpressure)
- 1 Swoole\Timer for periodic Heartbeat sends

Footprint: a single coroutine costs ~8KB of stack. 1000 concurrent tool
calls = ~8MB. Process restart is instant; no fork-bomb risk.
