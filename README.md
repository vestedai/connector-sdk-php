# Vested AI Connector SDK (PHP)

![Build](https://img.shields.io/github/actions/workflow/status/vestedsystems/connector-sdk-php/ci.yml?branch=main)
![Packagist](https://img.shields.io/packagist/v/vested-ai/connector-sdk-php)
![License](https://img.shields.io/github/license/vestedsystems/connector-sdk-php)
![PHP](https://img.shields.io/badge/php-%5E8.3-blue)

Connect any PHP service to the Vested AI platform. The SDK opens a long-lived gRPC stream to the hub, declares agents and tools over that stream, and dispatches tool calls to your handler code — no polling, no webhook setup, no managing your own LLM client. The hub handles model selection, prompt composition, and conversation state; your connector owns the business logic.

## Install

```bash
composer require vested-ai/connector-sdk-php
```

Or pull the pre-built Docker image (PHP 8.3 + Swoole bundled):

```bash
docker pull vestedai/vested-ai-connector-sdk-php:0.2.4
```

## 30-Second Example

```php
<?php
// bootstrap.php
require_once __DIR__ . '/vendor/autoload.php';

use Vested\Connect\Sdk\ConnectorApp;
use Vested\Connect\Sdk\Attribute\{Agent, Model, Instruction, Tool};
use Vested\Connect\Sdk\Tool\{ToolHandler, ToolContext};

#[Agent(key: 'myapp.orders', name: 'Orders')]
#[Model(provider: 'openai', name: 'gpt-4o')]
#[Instruction(type: 'system', position: 0, body: 'You help users look up their orders.')]
class OrdersAgent {}

#[Tool(
    agentKey:     'myapp.orders',
    key:          'myapp.orders.get',
    name:         'Get order',
    description:  'Returns an order by ID.',
    inputSchema:  ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']],
    outputSchema: ['type' => 'object', 'properties' => ['status' => ['type' => 'string']], 'required' => ['status']],
)]
final class GetOrder implements ToolHandler {
    public function handle(array $args, ToolContext $ctx): array {
        return ['status' => 'shipped'];   // replace with a real lookup
    }
}

return ConnectorApp::create()
    ->scanNamespace('', __DIR__)
    ->build();
```

```bash
VESTED_CONNECTOR_TOKEN=eyJ… VESTED_CONNECTOR_HUB=hub.example.com:4443 \
vendor/bin/vested-connect worker --bootstrap=./bootstrap.php
```

## What This Is

A **connector** is a long-lived worker process that registers one or more agents with the Vested AI hub. Each agent carries a model selection, a set of instruction blocks, and a set of tool definitions. Admins can override instruction bodies and disable tools in the admin UI; the connector's declared baseline is the floor that overrides are layered on top of. The hub routes LLM tool calls back to the connector over the same stream; the connector dispatches them to your handler code and returns results.

This differs from writing your own LLM client. The connector does not call the LLM directly. It registers capability and responds to callbacks. Prompt composition, model routing, conversation history, streaming to end users — all of that lives in the hub. The connector's surface area is: "declare what agents exist, implement what the tools do."

## Documentation

| Document | What's in it |
|---|---|
| [Quickstart](docs/quickstart.md) | Install, write your first agent + tool, run the worker, verify in the admin UI |
| [Concepts](docs/concepts.md) | Agents, tools, instructions, baselines vs overrides, inheritance state machine, reconciliation |
| [API reference](docs/api.md) | `ConnectorApp`, `AgentBuilder`, attributes, `ToolHandler`, `ToolContext` |
| [Operations](docs/operations.md) | Docker, env vars, observability, reconnect supervisor, DB pool sizing, gotchas |
| [Upgrading](docs/upgrading.md) | v0.1 → v0.2 migration; v0.2.x patch notes |
| [Doc index](docs/README.md) | Full table of contents including protocol reference |

## License + Status

MIT. Current release: **v0.2.4** (Swoole runtime, supervisor reconnect, PDO pool guidance, Monolog/Swoole fix). Production-ready; used in the alsaif Magento connector.
