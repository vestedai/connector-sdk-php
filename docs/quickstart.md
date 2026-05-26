# Quickstart

Reading time: ~15 minutes. By the end, a connector worker is running locally, registered with the hub, and the agent is visible in the admin UI.

## Prerequisites

- **PHP 8.3+** with `ext-swoole` 5.1+ (`pecl install swoole`)
- **Composer** 2.x
- **Docker** (optional; use if you prefer not to install Swoole locally)
- A running Vested AI instance with admin access

## 1. Get a Connector Token

Sign in to the admin UI. Navigate to **Integrations → Add integration**. Fill in:

- **Namespace** — a short identifier for your connector (e.g., `myapp`). All agent and tool keys must start with this namespace.
- **Name** — human-readable label.

Click **Create**. Copy the token shown — it is displayed only once.

## 2. Create a Project

```bash
mkdir my-connector && cd my-connector
composer init --name="myorg/my-connector" --require="vested-ai/connector-sdk-php:^0.2" -n
composer install
```

Expected directory shape after install:

```
my-connector/
  composer.json
  vendor/
    bin/vested-connect
  bootstrap.php          ← you will create this
  src/
    Agents/
    Tools/
```

## 3. Declare Your First Agent and Tool

Create `src/Agents/GreetingAgent.php`:

```php
<?php

declare(strict_types=1);

namespace MyApp\Agents;

use Vested\Connect\Sdk\Attribute\Agent;
use Vested\Connect\Sdk\Attribute\Instruction;
use Vested\Connect\Sdk\Attribute\Model;

#[Agent(key: 'myapp.greeting', name: 'Greeting Agent', description: 'Says hello')]
#[Model(provider: 'openai', name: 'gpt-4o')]
#[Instruction(type: 'system', position: 0, body: 'You greet users warmly and briefly.')]
class GreetingAgent {}
```

Create `src/Tools/SayHello.php`:

```php
<?php

declare(strict_types=1);

namespace MyApp\Tools;

use Vested\Connect\Sdk\Attribute\Tool;
use Vested\Connect\Sdk\Tool\ToolContext;
use Vested\Connect\Sdk\Tool\ToolHandler;

#[Tool(
    agentKey:     'myapp.greeting',
    key:          'myapp.greeting.hello',
    name:         'Say hello',
    description:  'Returns a greeting for the given name.',
    inputSchema:  ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']],
    outputSchema: ['type' => 'object', 'properties' => ['message' => ['type' => 'string']], 'required' => ['message']],
)]
final class SayHello implements ToolHandler
{
    public function handle(array $args, ToolContext $ctx): array
    {
        return ['message' => "Hello, {$args['name']}!"];
    }
}
```

## 4. Wire bootstrap.php

Create `bootstrap.php` in the project root:

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Vested\Connect\Sdk\ConnectorApp;

return ConnectorApp::create()
    ->scanNamespace('MyApp\\Agents', __DIR__ . '/src/Agents')
    ->scanNamespace('MyApp\\Tools',  __DIR__ . '/src/Tools')
    ->build();
```

`bootstrap.php` must return a `ConnectorApp` instance. The CLI loads this file inside a Swoole coroutine context.

## 5. Run the Worker Locally

```bash
VESTED_CONNECTOR_TOKEN=eyJ… \
VESTED_CONNECTOR_HUB=ai-connect.example.com:4443 \
vendor/bin/vested-connect worker --bootstrap=./bootstrap.php
```

On success:

```
connected to hub  connector_id=42 namespace=myapp max_concurrent=16
```

The worker stays running. Leave it running for step 6.

To use plaintext gRPC against a local dev hub, add `--insecure`.

## 6. Verify in the Admin UI

1. Navigate to **Integrations**. The connector's status badge should read **active** (green).
2. Navigate to **Agents**. The `myapp.greeting` agent should appear with source column showing your connector name.
3. Open the agent detail. The version is auto-published (first registration publishes immediately).
4. Open the **Test** tab on the agent. Invoke the `myapp.greeting.hello` tool with `{"name": "World"}`. The response should be `{"message": "Hello, World!"}`.

## Next

[Concepts](concepts.md)
