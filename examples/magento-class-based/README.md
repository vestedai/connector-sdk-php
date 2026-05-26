# Magento connector walkthrough

Build a vested-ai connector for a Magento 2 store. Two agents (operations +
insights), three tools, deployed as a Docker worker. Builds on
[quickstart](../../docs/quickstart.md).

---

## What you'll build

- `acme_commerce.operations` agent with one tool (`order_status`)
- `acme_commerce.insights` agent with two tools (`bestsellers`,
  `inventory_summary`)
- PHP daemon that talks to Magento REST and directly to the Magento DB
- Deployed as a Docker container connected to the vested-ai hub

---

## Prerequisites

- Magento 2 store with a REST API **integration token** (admin scope)
- Magento DB read-only credentials — create a dedicated DB user with `SELECT`
  only; the connector must not have write access
- vested-ai connector token — see
  [quickstart §1](../../docs/quickstart.md#1-get-a-connector-token)
- Docker, PHP 8.3, and Composer locally

---

## 1. Scaffold the project

```bash
composer create-project --prefer-dist vested-ai/connector-skeleton acme-connector
cd acme-connector
```

After scaffolding, your layout will be:

```
acme-connector/
├── bootstrap.php
├── bootstrap-container.php
├── composer.json
├── src/
│   ├── Operations/
│   │   ├── OperationsAgent.php
│   │   └── OrderStatusHandler.php
│   ├── Insights/
│   │   ├── InsightsAgent.php
│   │   ├── BestsellersHandler.php
│   │   └── InventorySummaryHandler.php
│   └── Magento/
│       ├── RestClient.php
│       ├── PdoConnectionPool.php
│       └── PooledMagentoConnection.php
└── docker/
    ├── Dockerfile
    └── k8s-deployment.yaml
```

---

## 2. Declare the operations agent

Agent marker classes carry `#[Agent]`, `#[Model]`, and one or more
`#[Instruction]` attributes. No methods — the SDK reflection scanner picks
these up at boot.

```php
#[Agent(
    key:         'acme_commerce.operations',
    name:        'Commerce Operations Assistant',
    description: 'Day-to-day order and catalog queries for internal staff.',
)]
#[Model(provider: 'openai', name: 'gpt-4o-mini', config: ['temperature' => 0.2])]
#[Instruction(type: 'system', position: 0, body: <<<'PROMPT'
You are an operations assistant for an e-commerce store built on Magento 2.
Help staff look up orders, check inventory, and find products. Be concise
and direct. When a SKU is known, always include it in your response.
PROMPT)]
#[Instruction(type: 'task', position: 1, body: <<<'PROMPT'
For order questions, use order_status with the increment_id (e.g. "100012345").
If a tool returns found=false, tell the user plainly rather than guessing.
PROMPT)]
final class OperationsAgent {}
```

Full file with namespace/imports: [`src/Operations/OperationsAgent.php`](src/Operations/OperationsAgent.php)

---

## 3. Write the order_status tool

`ToolHandler` implementations receive `$args` (the validated JSON input) and
a `ToolContext` that carries the logger and caller identity.

```php
// src/Operations/OrderStatusHandler.php
<?php declare(strict_types=1);

namespace Acme\Commerce\Operations;

use Acme\Commerce\Magento\RestClient;
use Vested\Connect\Sdk\Attribute\Tool;
use Vested\Connect\Sdk\Tool\ToolContext;
use Vested\Connect\Sdk\Tool\ToolHandler;

#[Tool(
    agentKey:       'acme_commerce.operations',
    key:            'acme_commerce.operations.order_status',
    name:           'Order status',
    description:    'Current status, line items, and shipping tracks for an order.',
    deadlineMs:     30000,
    maxResultBytes: 32768,
)]
final class OrderStatusHandler implements ToolHandler
{
    public function __construct(private readonly RestClient $rest) {}

    public function handle(array $args, ToolContext $ctx): array
    {
        $incrementId = (string) ($args['order_id']
            ?? throw new \InvalidArgumentException('order_id required'));

        $resp = $this->rest->get('/rest/V1/orders', [
            'searchCriteria[filterGroups][0][filters][0][field]'         => 'increment_id',
            'searchCriteria[filterGroups][0][filters][0][value]'         => $incrementId,
            'searchCriteria[filterGroups][0][filters][0][conditionType]' => 'eq',
            'searchCriteria[pageSize]'                                   => 1,
        ]);

        $orders = (array) ($resp['items'] ?? []);
        if ($orders === []) {
            return ['found' => false];
        }
        $order = $orders[0];
        $items = [];
        foreach ((array) ($order['items'] ?? []) as $it) {
            if (!empty($it['parent_item_id'])) { continue; } // skip configurable children
            $items[] = ['sku' => (string) $it['sku'], 'name' => (string) ($it['name'] ?? ''),
                        'qty_ordered' => (float) ($it['qty_ordered'] ?? 0)];
        }
        return ['found' => true, 'order_number' => (string) $order['increment_id'],
                'status' => (string) $order['status'], 'grand_total' => (float) $order['grand_total'],
                'items' => $items];
    }
}
```

### The RestClient

`RestClient` wraps Guzzle with the integration token, maps HTTP error codes to
typed exceptions, and strips control characters from Magento's occasionally
malformed JSON responses.

Full file: [`src/Magento/RestClient.php`](src/Magento/RestClient.php)

Key constructor signature:

```php
public function __construct(
    private readonly string $baseUrl,           // https://store.example.com
    private readonly string $integrationToken,  // MAGENTO_INTEGRATION_TOKEN env
    private readonly LoggerInterface $logger = new NullLogger(),
    int $timeoutSeconds = 10,
)
```

---

## 4. Declare the insights agent

Same structure as `OperationsAgent` — change the key, name, description,
model (lower temperature for analytics), and instructions:

```php
#[Agent(
    key:         'acme_commerce.insights',
    name:        'Commerce Business Insights',
    description: 'Sales analytics and inventory health for managers and analysts.',
)]
#[Model(provider: 'openai', name: 'gpt-4o', config: ['temperature' => 0.1])]
#[Instruction(type: 'system', position: 0, body: <<<'PROMPT'
You are a business analytics assistant. Always cite the time range and
store context for every metric. Default to the last 30 days when no date
range is given.
PROMPT)]
#[Instruction(type: 'task', position: 1, body: <<<'PROMPT'
For "what's selling" questions use bestsellers. For inventory health use
inventory_summary. Suggest one concrete follow-up action after each result.
PROMPT)]
final class InsightsAgent {}
```

Full file: [`src/Insights/InsightsAgent.php`](src/Insights/InsightsAgent.php)

---

## 5. Write the bestsellers tool (DB-direct)

Insights tools query the Magento DB directly — REST aggregation endpoints
are too slow for analytic workloads. This tool uses Illuminate's query
builder via a `Capsule` instance wired in `bootstrap-container.php`.

```php
// src/Insights/BestsellersHandler.php
<?php declare(strict_types=1);

namespace Acme\Commerce\Insights;

use Illuminate\Database\Capsule\Manager as Capsule;
use Vested\Connect\Sdk\Attribute\Tool;
use Vested\Connect\Sdk\Tool\ToolContext;
use Vested\Connect\Sdk\Tool\ToolHandler;

#[Tool(
    agentKey:       'acme_commerce.insights',
    key:            'acme_commerce.insights.bestsellers',
    name:           'Bestselling products',
    description:    'Top N products by units sold or revenue over a date range.',
    deadlineMs:     60000,
    maxResultBytes: 16384,
)]
final class BestsellersHandler implements ToolHandler
{
    public function __construct(private readonly Capsule $capsule) {}

    public function handle(array $args, ToolContext $ctx): array
    {
        $startAt = ((string) $args['start_date']) . ' 00:00:00';
        $endAt   = ((string) $args['end_date'])   . ' 23:59:59';
        $rankBy  = (string) ($args['rank_by'] ?? 'units');  // 'units' | 'revenue'
        $limit   = (int) ($args['limit'] ?? 10);
        $col     = $rankBy === 'revenue' ? 'revenue' : 'units';

        $rows = $this->capsule->connection('magento')
            ->table('sales_order_item as soi')
            ->join('sales_order as so', 'soi.order_id', '=', 'so.entity_id')
            ->where('so.subtotal_invoiced', '>', 0)       // invoiced = successful
            ->whereBetween('so.created_at', [$startAt, $endAt])
            ->whereNull('soi.parent_item_id')             // skip configurable children
            ->groupBy('soi.sku', 'soi.name')
            ->orderByDesc($col)
            ->limit($limit)
            ->selectRaw('soi.sku')
            ->selectRaw('soi.name')
            ->selectRaw('COALESCE(SUM(soi.qty_ordered), 0) AS units')
            ->selectRaw('COALESCE(SUM(soi.row_total),   0) AS revenue')
            ->get();

        return [
            'ranked_by' => $rankBy,
            'products'  => $rows->map(fn ($r) => [
                'sku'        => (string) $r->sku,
                'name'       => (string) $r->name,
                'units_sold' => (float) $r->units,
                'revenue'    => (float) $r->revenue,
            ])->all(),
        ];
    }
}
```

> **Connection pool** — Use a Swoole-safe PDO pool under the `magento`
> connection name. See
> [operations.md — database connection pool](../../docs/operations.md#database-connection-pool)
> for the rationale. The pool is wired in `bootstrap-container.php`, shown
> next.

---

## 6. Bootstrap the container and Capsule

Two files wire everything together.

### bootstrap-container.php

Builds the Illuminate Container, registers the Swoole-safe
`PooledMagentoConnection` for the `magento` connection name, and returns
the container. The key section is the custom driver extension — it lazily
builds a `PdoConnectionPool` (one real PDO per Swoole coroutine) so
parallel tool calls don't interleave MySQL wire-protocol packets.

```php
// bootstrap-container.php  (abbreviated — see src/Magento/ for pool classes)
<?php declare(strict_types=1);

use Acme\Commerce\Magento\PdoConnectionPool;
use Acme\Commerce\Magento\PooledMagentoConnection;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;

$container = new Container();
$host = getenv('MAGENTO_DB_HOST') ?: throw new \RuntimeException('MAGENTO_DB_HOST required');
$db   = getenv('MAGENTO_DB_NAME') ?: throw new \RuntimeException('MAGENTO_DB_NAME required');
$user = getenv('MAGENTO_DB_USER') ?: throw new \RuntimeException('MAGENTO_DB_USER required');
$pass = getenv('MAGENTO_DB_PASSWORD') ?: throw new \RuntimeException('MAGENTO_DB_PASSWORD required');
$size = (int) (getenv('MAGENTO_DB_POOL_SIZE') ?: 8);
$port = (int) (getenv('MAGENTO_DB_PORT') ?: 3306);

$capsule = new Capsule($container);
$capsule->getDatabaseManager()->extend('magento', function (array $cfg)
    use ($host, $port, $db, $user, $pass, $size)
{
    static $pool = null;
    if ($pool === null) {
        $opts = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                 \PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION TRANSACTION READ ONLY'];
        $pool = new PdoConnectionPool(
            factory: fn () => new \PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, $opts),
            size: $size,
        );
    }
    return new PooledMagentoConnection(pool: $pool, database: $db, config: $cfg);
});
$capsule->addConnection(['driver' => 'mysql', 'host' => $host, 'port' => $port,
    'database' => $db, 'username' => $user, 'password' => $pass,
    'charset' => 'utf8mb4'], 'magento');
$capsule->setEventDispatcher(new Dispatcher($container));
$capsule->setAsGlobal();  // Do NOT call bootEloquent() — query builder only
$container->instance(Capsule::class, $capsule);
return $container;
```

`PdoConnectionPool` and `PooledMagentoConnection` are included under
`src/Magento/` in this example and can be copied verbatim.

### bootstrap.php

```php
// bootstrap.php
<?php declare(strict_types=1);

use Acme\Commerce\Magento\RestClient;
use Illuminate\Container\Container;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Vested\Connect\Sdk\ConnectorApp;

require_once __DIR__ . '/vendor/autoload.php';

/** @var Container $container */
$container = require __DIR__ . '/bootstrap-container.php';

$logger = new Logger('acme-connector');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));
$container->instance(LoggerInterface::class, $logger);

$container->singleton(RestClient::class, fn () => new RestClient(
    baseUrl:          (string) getenv('MAGENTO_BASE_URL'),
    integrationToken: (string) getenv('MAGENTO_INTEGRATION_TOKEN'),
    logger:           $logger,
));

return ConnectorApp::create()
    ->withLogger($logger)
    ->withWorkerPoolSize((int) (getenv('WORKER_POOL_SIZE') ?: 4))
    ->scanNamespace('Acme\\Commerce\\Operations', __DIR__ . '/src/Operations', $container)
    ->scanNamespace('Acme\\Commerce\\Insights',   __DIR__ . '/src/Insights',   $container)
    ->build();
```

---

## 7. Run locally

```bash
export VESTED_CONNECTOR_TOKEN=ct_...
export VESTED_CONNECTOR_HUB=hub.vested.ai:4443
export MAGENTO_BASE_URL=https://store.example.com
export MAGENTO_INTEGRATION_TOKEN=...
export MAGENTO_DB_HOST=db.example.com
export MAGENTO_DB_NAME=magento
export MAGENTO_DB_USER=magento_ro
export MAGENTO_DB_PASSWORD=...

vendor/bin/vested-connect worker --bootstrap=./bootstrap.php
```

Expected log output:

```
[acme-connector] INFO  Connected to hub hub.vested.ai:4443
[acme-connector] INFO  Registered agent acme_commerce.operations (1 tool)
[acme-connector] INFO  Registered agent acme_commerce.insights (2 tools)
[acme-connector] INFO  Worker pool started size=4
```

Set `LOG_LEVEL=debug` for per-request Magento REST timings and DB query durations.

---

## 8. Verify in the admin UI

1. Open the vested-ai admin UI and navigate to **Connectors**.
2. Both agents should appear under your connector's namespace with a green
   status pill.
3. Open the **Test** tab on `acme_commerce.insights`.
4. Run a test query on `bestsellers`:
   `{"start_date":"2025-01-01","end_date":"2025-01-31"}` — a ranked product
   list should come back within a few seconds.
5. Confirm the **Connector status** pill is green.

---

## 9. Deploy as Docker

### Dockerfile

```dockerfile
FROM vestedai/vested-ai-connector-sdk-php:0.2.x

WORKDIR /app

COPY composer.json composer.lock /app/
RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY bootstrap.php bootstrap-container.php /app/
COPY src/ /app/src/

USER 1000:1000

# Entrypoint is inherited from the SDK image; just override CMD
CMD ["worker", "--bootstrap=/app/bootstrap.php"]
```

### Kubernetes Deployment

```yaml
# docker/k8s-deployment.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: acme-connector
spec:
  replicas: 1
  selector:
    matchLabels: { app: acme-connector }
  template:
    metadata:
      labels: { app: acme-connector }
    spec:
      containers:
        - name: connector
          image: your-registry/acme-connector:latest
          env:
            - { name: VESTED_CONNECTOR_TOKEN,    valueFrom: { secretKeyRef: { name: acme-connector-secrets, key: VESTED_CONNECTOR_TOKEN    } } }
            - { name: MAGENTO_INTEGRATION_TOKEN, valueFrom: { secretKeyRef: { name: acme-connector-secrets, key: MAGENTO_INTEGRATION_TOKEN } } }
            - { name: MAGENTO_DB_PASSWORD,        valueFrom: { secretKeyRef: { name: acme-connector-secrets, key: MAGENTO_DB_PASSWORD        } } }
            - { name: VESTED_CONNECTOR_HUB,  value: "hub.vested.ai:4443"         }
            - { name: MAGENTO_BASE_URL,       value: "https://store.example.com"  }
            - { name: MAGENTO_DB_HOST,        value: "db.example.com"             }
            - { name: MAGENTO_DB_NAME,        value: "magento"                    }
            - { name: MAGENTO_DB_USER,        value: "magento_ro"                 }
            - { name: WORKER_POOL_SIZE,       value: "4"                          }
```

```bash
kubectl create secret generic acme-connector-secrets \
  --from-literal=VESTED_CONNECTOR_TOKEN=ct_... \
  --from-literal=MAGENTO_INTEGRATION_TOKEN=... \
  --from-literal=MAGENTO_DB_PASSWORD=...
```

---

## 10. Observability and troubleshooting

**Logs** — the worker writes structured logs to stdout:

```bash
kubectl logs -f deployment/acme-connector
```

**Common issues**

| Symptom | Likely cause | Fix |
|---|---|---|
| `VESTED_CONNECTOR_TOKEN invalid` | Token revoked or wrong env | Re-issue token in admin UI |
| `MAGENTO_BASE_URL must use https://` | HTTP URL passed | Change to `https://` |
| `MAGENTO_DB_HOST required` | Missing env var | Check Secret or `.env` |
| Tool returns 401 from Magento | Integration token expired | Re-create the Magento integration |
| `PdoConnectionPool timed out` | Pool exhausted under load | Raise `MAGENTO_DB_POOL_SIZE` |

For the full troubleshooting reference see
[operations.md — troubleshooting](../../docs/operations.md#troubleshooting).

---

## Next steps

- **Add more tools** — implement `ToolHandler`, annotate with `#[Tool]`, drop
  into the scanned namespace. See
  [api.md — ToolHandler interface](../../docs/api.md#toolhandler-interface).
- **Override instructions per-user in the admin UI** — see
  [concepts.md — baselines vs overrides](../../docs/concepts.md#baselines-vs-overrides).
- **Production deploy checklist** — see
  [operations.md](../../docs/operations.md).
