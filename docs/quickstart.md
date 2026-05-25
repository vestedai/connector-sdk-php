# Quickstart

## 1. Get a connector token from the admin UI

Sign in to the admin UI, navigate to **Integrations → Add integration**,
fill in namespace + name, and copy the token shown (it appears once).

## 2. Pick a deploy mode

| Mode | When to use |
|---|---|
| **Docker** | Zero PHP setup. `docker run vested-ai-sdks/php:latest -e VESTED_CONNECTOR_TOKEN=... -v ./bootstrap.php:/app/bootstrap.php` |
| **CLI** | You manage your own PHP host. `composer require vested-ai/connector-sdk-php` then `vendor/bin/vested-connect worker --bootstrap=./bootstrap.php`. Run `vendor/bin/vested-connect doctor` to verify required extensions. |
| **Embedded** | You want the connector inside your existing Symfony/Laravel daemon. Use `Vested\Connect\Sdk\Process\ParentProcess` directly. |

## 3. Write a bootstrap

Two styles — see `examples/minimal-builder.php` and `examples/magento-class-based/`.

## 4. Run

```bash
VESTED_CONNECTOR_TOKEN=eyJ... vendor/bin/vested-connect worker --bootstrap=./bootstrap.php
```

You should see:

```
connected to hub  connector_id=42 namespace=magento max_concurrent=16
```

Then the agent appears in the admin UI and is ready to receive tool calls.

## 5. Verify

In the admin UI under **Integrations** the connector's status badge
should read "active". On the agent detail page you'll see your declared
agent(s) with the auto-published version.
