#!/usr/bin/env bash
# Live smoke: mint a connector via Laravel artisan, run the SDK Docker
# image against the public hub, assert the agent shows up in Laravel.
#
# Prereqs:
#   - kubectl context pointing at the alsaif-ai cluster
#   - docker on PATH (for the SDK image)
#   - the SDK image built locally as vested-ai-sdks-php:smoke OR
#     pulled from the registry

set -euo pipefail
cd "$(dirname "$0")/.."

IMAGE="${IMAGE:-vested-ai-sdks-php:smoke}"
NAMESPACE="${NAMESPACE:-sdk_live_smoke}"
HUB_ADDR="${HUB_ADDR:-ai-connect.alsaifgallery.com:4443}"

echo "1. Mint a connector + token via Laravel"
SETUP_JSON=$(kubectl -n alsaif-ai exec deploy/ai-core-laravel -- \
  php artisan connector:e2e-setup --namespace="$NAMESPACE" 2>&1 | tail -1)
TOKEN=$(echo "$SETUP_JSON" | python3 -c 'import sys,json; print(json.load(sys.stdin)["token"])')
CONNECTOR_ID=$(echo "$SETUP_JSON" | python3 -c 'import sys,json; print(json.load(sys.stdin)["connector_id"])')
echo "   connector_id=$CONNECTOR_ID"

echo "2. Prepare a bootstrap that declares one agent under the namespace"
mkdir -p /tmp/sdk-smoke
cat > /tmp/sdk-smoke/bootstrap.php <<PHP
<?php
require_once '/app/vendor/autoload.php';
use Vested\Connect\Sdk\ConnectorApp;
use Vested\Connect\Sdk\Tool\ToolContext;
return ConnectorApp::create()
    ->agent('${NAMESPACE}.echo')
        ->withModel('openai', 'gpt-4o')
        ->withInstruction('Echo agent for live smoke.', position: 0)
        ->withTool(
            key: '${NAMESPACE}.echo.bounce', name: 'Bounce', description: '',
            inputSchema: ['type'=>'object','properties'=>['s'=>['type'=>'string']],'required'=>['s']],
            outputSchema: ['type'=>'object','properties'=>['echoed'=>['type'=>'string']],'required'=>['echoed']],
            handler: fn(array \$a, ToolContext \$c) => ['echoed' => \$a['s']],
        )
    ->endAgent()
    ->build();
PHP

echo "3. Run the SDK Docker image against the hub"
docker run --rm -d --name sdk-live-smoke \
  -e VESTED_CONNECTOR_TOKEN="$TOKEN" \
  -e VESTED_CONNECTOR_HUB="$HUB_ADDR" \
  -v /tmp/sdk-smoke/bootstrap.php:/app/bootstrap.php:ro \
  "$IMAGE"
trap "docker rm -f sdk-live-smoke >/dev/null 2>&1 || true" EXIT

echo "4. Wait 8s for handshake + Register"
sleep 8

echo "5. Assert the agent landed in Laravel"
COUNT=$(kubectl -n alsaif-ai exec deploy/ai-core-laravel -- php artisan tinker --execute="echo App\\Models\\Organization::withoutScope(fn () => App\\Models\\Agent::where('connector_id', $CONNECTOR_ID)->count());" 2>&1 | tail -1)
echo "   connector-owned agents: $COUNT"
[ "$COUNT" = "1" ] || { echo "FAIL: expected 1 agent"; exit 1; }

echo "6. Clean up"
docker rm -f sdk-live-smoke >/dev/null 2>&1 || true
kubectl -n alsaif-ai exec deploy/ai-core-laravel -- \
  php artisan tinker --execute="App\\Models\\Organization::withoutScope(fn () => App\\Models\\Connector::find($CONNECTOR_ID)?->delete()); echo 'cleaned';" >/dev/null

echo "PASS: live smoke complete"
