# vested-ai/connector-sdk-php

PHP SDK for the Vested AI ConnectorHub. Lets your PHP service register
agents + tools with the platform over a long-lived gRPC stream.

## Three ways to deploy

| Mode | When to use |
|---|---|
| **Docker image** | Customer wants zero PHP setup. `docker run vestedai/vested-ai-connector-sdk-php:latest …` |
| **CLI binary** | Customer manages their own host. `composer require vested-ai/connector-sdk-php` + `vendor/bin/vested-connect worker …` |
| **Embedded library** | Customer wants to wire the connector into their existing Symfony/Laravel daemon supervisor. Build a `ConnectorApp` and call `$app->runSwooleDaemon($token, $hubAddr)` from inside `\Swoole\Coroutine\run(...)`. |

## Quickstart

See `docs/quickstart.md` and `examples/minimal-builder.php`.

## Requirements

PHP 8.3+, `ext-swoole`.
Run `vendor/bin/vested-connect doctor` to check.

v0.2 requires the Swoole PHP extension (`pecl install swoole`). For ext-grpc-based v0.1, pin to `^0.1`.
