# vested-ai/connector-sdk-php

PHP SDK for the Vested AI ConnectorHub. Lets your PHP service register
agents + tools with the platform over a long-lived gRPC stream.

## Three ways to deploy

| Mode | When to use |
|---|---|
| **Docker image** | Customer wants zero PHP setup. `docker run vested-ai-sdks/php:latest …` |
| **CLI binary** | Customer manages their own host. `composer require vested-ai/connector-sdk-php` + `vendor/bin/vested-connect worker …` |
| **Embedded library** | Customer wants to wire the connector into their existing Symfony/Laravel daemon supervisor. Use `Vested\Connect\Sdk\Process\ParentProcess` directly. |

## Quickstart

See `docs/quickstart.md` and `examples/minimal-builder.php`.

## Requirements

PHP 8.3+, `ext-grpc`, `ext-protobuf`, `ext-pcntl`, `ext-sockets`.
Run `vendor/bin/vested-connect doctor` to check.
