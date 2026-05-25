# magento-class-based example

A fully attribute-decorated example for a Magento-style connector.

## Run

```bash
cd vested-ai-sdks/php/examples/magento-class-based
composer install
VESTED_CONNECTOR_TOKEN=$(your-token-source) \
  ../../vendor/bin/vested-connect worker --bootstrap=./bootstrap.php
```

## Add a tool

Drop a new class under `src/Tools/`:

```php
#[Tool(agentKey: 'magento.products', key: 'magento.products.get', ...)]
final class GetProduct implements ToolHandler { ... }
```

The reflection scanner picks it up on the next daemon restart.
