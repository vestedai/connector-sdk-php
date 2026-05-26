# Authentication

Connector workers authenticate with a JWT issued by the Laravel control
plane. The token is presented once at stream open; there is no mid-stream
re-authentication challenge.

---

## Token shape

Tokens are RS256 JWTs signed with the core's RSA private key.

**Header:**

```json
{
  "alg": "RS256",
  "typ": "JWT"
}
```

No `kid` header is present in v1; the hub verifies against a single
configured public key.

**Claims:**

```json
{
  "iss": "vested-core",
  "sub": "connector:<connector_id>",
  "org": "<organization_id>",
  "ns":  "<namespace>",
  "iat": 1748304000,
  "exp": 1779840000,
  "jti": "<uuid>"
}
```

| Claim | Value | Purpose |
|---|---|---|
| `iss` | `vested-core` | Fixed issuer identifier. Hub rejects any other value. |
| `sub` | `connector:<id>` | Connector row ID prefixed with `connector:`. |
| `org` | organization row ID (string) | Hub checks that this matches `connectors.organization_id`. |
| `ns` | namespace string (e.g. `magento`) | Hub checks that this matches `connectors.namespace`. |
| `iat` | Unix timestamp | Issued-at. |
| `exp` | Unix timestamp | Expiry. Default TTL at issuance: 1 year. Configurable on mint. |
| `jti` | UUID | Per-token unique ID used for revocation lookup. |

The full token is never stored in cleartext. The `connectors` table stores
only a bcrypt hash (`token_hash`) and the last 4 characters (`token_prefix`)
for admin display.

---

## How it's sent

The JWT is sent as an HTTP/2 metadata header on stream open:

```
x-connector-token: <jwt>
```

It is **not** included in any protobuf message body. This ensures tokens
never appear in protobuf-encoded audit or debug logs.

---

## Verification

On every `Connect` call the hub:

1. Reads the `x-connector-token` metadata header. Missing → `Unauthenticated`.
2. Verifies the RS256 signature against the configured public key.
3. Checks `exp` is in the future.
4. Checks `iss == "vested-core"`.
5. Checks `sub` starts with `"connector:"`.
6. Looks up `jti` in the revocation store (Redis-backed via Laravel). Revoked
   → `Unauthenticated`.
7. Loads the `connectors` row for the extracted ID. Row missing, or
   `status != "active"` → `Unauthenticated`.
8. Confirms `org` claim matches `connectors.organization_id` and `ns` claim
   matches `connectors.namespace`. Mismatch → `Unauthenticated`.

Any failure at steps 1–8 closes the stream with gRPC status code
`Unauthenticated` (16). The connection attempt is logged to
`connector_audit_events`.

---

## Token rotation

When an admin rotates a token in the admin UI:

1. A new JWT is issued (new `jti`, same `connector_id`).
2. The old `jti` is written to the revocation store with a TTL equal to
   the old token's remaining lifetime.
3. The hub receives a `POST /internal/connectorhub/goaway` call from Laravel
   with `reason = "token_rotated"`.
4. The hub sends `GoAway{reason: "token_rotated"}` to any active stream for
   that connector.
5. The worker exits with code 78 (`EX_CONFIG`).
6. The operator provides the new token to the worker (e.g. via environment
   variable update + rolling restart). The worker reconnects with the new
   token.

Best practice: use a rolling update so the worker restarts with the new
token before the old token's revocation TTL expires. The PHP SDK supervisor
exits on `GoAway(token_rotated)` and does not retry automatically — the
process manager must restart it with the updated token.

---

## Revocation

When an admin revokes a connector:

1. The current `jti` is written to the revocation store.
2. `connectors.status` is set to `revoked`.
3. The hub sends `GoAway{reason: "revoked"}` to any active stream.
4. The worker exits with code 78.
5. **The worker must not reconnect.** A revoked token will be rejected at
   stream open (`Unauthenticated`). The supervisor treats exit code 78 as
   terminal and does not retry.

---

## Failure modes

| Scenario | When detected | Hub response |
|---|---|---|
| Missing `x-connector-token` header | Stream open | `Unauthenticated` (gRPC 16), stream closed |
| Bad signature | Stream open | `Unauthenticated` (gRPC 16), stream closed |
| Expired token (`exp` in the past) | Stream open | `Unauthenticated` (gRPC 16), stream closed |
| Revoked `jti` (pre-existing revocation) | Stream open | `Unauthenticated` (gRPC 16), stream closed |
| Token revoked mid-stream | Any time during frame loop | `GoAway{reason: "revoked"}`, then stream closed |
| Token rotated mid-stream | Any time during frame loop | `GoAway{reason: "token_rotated"}`, then stream closed |
| Connector status `!= active` | Stream open | `Unauthenticated` (gRPC 16), stream closed |
| Claim / connector row mismatch | Stream open | `Unauthenticated` (gRPC 16), stream closed |

---

## Next

[Lifecycle](lifecycle.md)
