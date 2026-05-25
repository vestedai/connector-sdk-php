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
