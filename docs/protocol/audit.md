# Audit Events

Every significant connector lifecycle event is appended to
`connector_audit_events`. The table is append-only; rows are never updated
or deleted by application code.

---

## What's audited

- Every stream connect and disconnect.
- Every `Register` frame outcome (accepted, rejected, no-op).
- Every token rotation and revocation (via the hub's `GoAway` path).

Tool-call events (`tool_call_started`, `tool_call_completed`, etc.) are
tracked in Prometheus metrics and structured logs. They are not written to
`connector_audit_events` in the current implementation.

---

## Table schema

```sql
CREATE TABLE connector_audit_events (
  id              bigserial     PRIMARY KEY,
  connector_id    bigint        NOT NULL REFERENCES connectors(id) ON DELETE CASCADE,
  organization_id bigint        NOT NULL REFERENCES organizations(id),
  event_kind      varchar       NOT NULL,
  payload_json    jsonb         NULL,
  at              timestamptz   NOT NULL DEFAULT now()
);

CREATE INDEX ON connector_audit_events (connector_id, at DESC);
```

| Column | Type | Description |
|---|---|---|
| `id` | bigint | Auto-incrementing primary key. |
| `connector_id` | bigint | Foreign key to `connectors`. Row is deleted when the connector is deleted (`ON DELETE CASCADE`). |
| `organization_id` | bigint | Denormalized for query efficiency; matches `connectors.organization_id`. |
| `event_kind` | varchar | Identifies the event type (see table below). |
| `payload_json` | jsonb | Event-specific detail. Nullable. |
| `at` | timestamptz | Event timestamp. Defaults to `now()` if not supplied by the emitter. |

There is no `created_at` / `updated_at` pair because `timestamps = false`
on the `ConnectorAuditEvent` Eloquent model. `at` is the only timestamp.

---

## Event kinds

| `event_kind` | Emitted by | Trigger | Typical `payload_json` |
|---|---|---|---|
| `connect` | Hub (Go runtime) | Worker successfully completes `Hello` / `HelloAck` handshake. | `{"worker_id": "...", "sdk_language": "php", "sdk_version": "1.0.0"}` |
| `disconnect` | Hub (Go runtime) | Stream closes (any cause: clean half-close, abrupt TCP reset, `GoAway` followed by close). | *(null)* |
| `register_accepted` | Laravel (`ConnectorRegistryService`) | `Register` frame passes validation; new baseline and agent drafts are persisted. | `{"baseline_id": 42, "drafts": [{"agent_key": "magento.support", "version_id": 17, "orphan_count": 0}]}` |
| `register_rejected` | Laravel (`ConnectorRegistryService`) | `Register` frame fails validation. | `{"issues": [{"path": "agents[0].key", "code": "namespace_violation", "message": "..."}]}` |
| `register_accepted_noop` | Laravel (`ConnectorRegistryService`) | `Register` frame has a fingerprint already stored in the `connector_baselines` table (hub-restart replay guard). | `{"baseline_id": 42, "reason": "fingerprint_already_processed"}` |

**Events not confirmed in application source** (listed in the spec as planned
but not yet emitted by any code path in this repo): `connect_rejected`,
`connector_offline`, `token_rotated`, `connector_revoked`,
`baseline_orphaned_overrides`, tool-call event kinds. Operators should not
rely on these being present until the corresponding code lands.

---

## Querying

All rejected registrations for connector 7 in the last 24 hours:

```sql
SELECT at, payload_json
FROM connector_audit_events
WHERE connector_id = 7
  AND event_kind = 'register_rejected'
  AND at > now() - interval '24 hours'
ORDER BY at DESC;
```

Connection history for a connector (last 50 events):

```sql
SELECT at, event_kind, payload_json
FROM connector_audit_events
WHERE connector_id = $1
ORDER BY at DESC
LIMIT 50;
```

The composite index on `(connector_id, at DESC)` makes both queries index
scans.

---

## Retention

No automated retention policy exists in the current schema or application
code. The `connector_audit_events` table will grow indefinitely.

Operators running production workloads should implement a periodic cleanup.
Example using pg_cron or a scheduled job:

```sql
DELETE FROM connector_audit_events
WHERE at < now() - interval '90 days';
```

Run this during off-peak hours; the table has no partitioning in v1 so a
large delete will take a table-level lock briefly.

---

## Next

[docs index](../README.md)
