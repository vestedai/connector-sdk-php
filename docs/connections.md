# Tenant connections (platform connectors)

A **platform connector** is one deployment the platform operates for every
tenant it is granted to — marketing analytics, ad platforms, CRMs. Each tenant
links its OWN account (a Google login, a MoEngage workspace key) and binds it to
the agents it activates. On every tool call your worker receives that tenant's
connection, already chosen, sealed so only your worker can open it.

This is different from [per-user credentials](credentials.md): those belong to
the person asking; connections belong to the tenant and work in scheduled and
automated runs too.

## Declaring a profile

A profile is a kind of account. Put the attributes on the handler class itself —
PHP does not inherit class attributes.

```php
use Vested\Connect\Sdk\Attribute\ConnectionField;
use Vested\Connect\Sdk\Attribute\ConnectionProfile;
use Vested\Connect\Sdk\Connection\ConnectionOpContext;
use Vested\Connect\Sdk\Connection\ConnectionProfileHandler;
use Vested\Connect\Sdk\Connection\ConnectionResource;
use Vested\Connect\Sdk\Credential\CredentialValidation;

#[ConnectionProfile(key: 'google', title: 'Google account', authKind: 'oauth2', oauthProvider: 'google',
    scopes: ['analytics.readonly', 'adwords'], resourceDiscoverable: true, resourceTitle: 'Property')]
final class GoogleProfile implements ConnectionProfileHandler
{
    public function validate(ConnectionOpContext $ctx, array $credential): CredentialValidation
    {
        return CredentialValidation::ok();
    }

    public function listResources(ConnectionOpContext $ctx, array $credential): array
    {
        // $credential = ['access_token' => …, 'token_type' => 'Bearer', 'expires_at' => …]
        return array_map(
            fn (array $p) => new ConnectionResource($p['name'], $p['displayName'], ['currency' => $p['currencyCode']]),
            $this->ga4Admin->listProperties($credential['access_token']),
        );
    }
}

#[ConnectionProfile(key: 'moengage', title: 'MoEngage workspace', authKind: 'fields')]
#[ConnectionField(key: 'workspace_id', label: 'Workspace ID')]
#[ConnectionField(key: 'data_api_key', label: 'Data API key', type: 'password')]
final class MoengageProfile implements ConnectionProfileHandler { /* validate() calls the workspace */ }
```

- **oauth2** — the platform runs consent against its own OAuth client and refreshes
  tokens. You receive a short-lived `access_token`, never a refresh token or a
  client secret. `oauthProvider` must be one the platform has configured.
- **fields** — the tenant types the values; `validate()` runs before the platform
  stores them.
- **Resources** — what one connection can reach. With `resourceDiscoverable: true`
  the tenant admin picks from `listResources()`; otherwise declare
  `#[ConnectionResourceField]`s (never secrets: resources are stored unencrypted).

Register them, with the connector's private key ring:

```php
$app->withConnectionProfile(new GoogleProfile($ga4Admin))
    ->withConnectionProfile(new MoengageProfile($moengage));
```

The key ring is the same one per-user credentials use:
`VESTED_CREDENTIAL_PRIVATE_KEY` or `VESTED_CREDENTIAL_PRIVATE_KEY_FILE`. A super
admin generates the pair on the platform connector; the private half is shown once.

## Requiring a connection

```php
#[Agent(key: 'mkt.ga4', name: 'GA4')]
#[RequiresConnection(profile: 'google', scopes: ['analytics.readonly'])]
class Ga4Agent {}

#[Agent(key: 'mkt.moengage', name: 'MoEngage')]
#[RequiresConnection(profile: 'moengage', cardinality: 'many', labelHint: 'Market code')]
class MoengageAgent {}
```

Or `->requiresConnection('google', ['analytics.readonly'])` on an `AgentBuilder`.
A requirement is part of the agent, so it changes the baseline fingerprint;
profiles do not. A requirement naming an undeclared profile or scope stops the
worker at start-up.

## Using a connection in a tool

```php
public function handle(array $args, ToolContext $ctx): array
{
    $google   = $ctx->connections()->one('google');
    $property = $google->resource()['id'];              // plain data
    $token    = $google->credential()['access_token'];  // opened on first use

    // cardinality 'many':
    $ksa = $ctx->connections()->byLabel('moengage', 'sa');
    foreach ($ctx->connections()->all('moengage') as $workspace) { /* … */ }
}
```

`credential()` opens the envelope only after checking it was sealed for THIS
connector, THIS tenant and THIS connection; an envelope replayed from another
tenant's call throws. Cache clients per `(organizationId, connectionId)`, never
per process: one worker serves every tenant.
