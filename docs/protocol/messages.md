# Wire Messages

All traffic travels over the single `ConnectorHub.Connect` bidi stream.
The connector sends `ConnectorMsg` frames; the hub sends `HubMsg` frames.
Both are standard protobuf3 messages; field names below are proto snake_case.

Source of truth: `proto/vested/v1/connector_hub.proto` (package `vested.v1`).

---

## ConnectorMsg (worker → hub)

The wrapper message. Every frame from the connector is a `ConnectorMsg` with
exactly one `body` variant set.

```proto
message ConnectorMsg {
  oneof body {
    Hello            hello              = 1;
    Register         register           = 2;
    ToolCallResponse tool_call_response = 3;
    Heartbeat        heartbeat          = 4;
  }
}
```

| Field | Type | Note |
|---|---|---|
| `hello` | `Hello` | First frame on every stream. |
| `register` | `Register` | Declarative agent snapshot. May be sent more than once. |
| `tool_call_response` | `ToolCallResponse` | Reply to a hub-initiated `ToolCallRequest`. |
| `heartbeat` | `Heartbeat` | Keepalive; sent every 20 s by the SDK. |

---

## HubMsg (hub → worker)

```proto
message HubMsg {
  oneof body {
    HelloAck        hello_ack         = 1;
    RegisterAck     register_ack      = 2;
    ToolCallRequest tool_call_request = 3;
    HeartbeatAck    heartbeat_ack     = 4;
    GoAway          go_away           = 5;
  }
}
```

| Field | Type | Note |
|---|---|---|
| `hello_ack` | `HelloAck` | Hub's reply to `Hello`. |
| `register_ack` | `RegisterAck` | Hub's reply to `Register`. |
| `tool_call_request` | `ToolCallRequest` | Hub-initiated tool dispatch. |
| `heartbeat_ack` | `HeartbeatAck` | Hub's reply to `Heartbeat`. |
| `go_away` | `GoAway` | Hub asks the worker to disconnect. |

---

## Hello

Sent by the worker as the **first frame** on every stream, within 5 s of
opening. The hub closes with `DeadlineExceeded` if no `Hello` arrives in time.
The JWT is carried in the `x-connector-token` HTTP/2 header, not in this
message, so tokens never appear in protobuf-encoded logs.

```proto
message Hello {
  string sdk_language = 1;  // "php", "python", "go", …
  string sdk_version  = 2;  // semver string
  string worker_id    = 3;  // connector-chosen; appears in audit log
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `sdk_language` | string | yes | Language identifier; used in admin UI and audit log. |
| `sdk_version` | string | yes | Semver of the connecting SDK. |
| `worker_id` | string | yes | Unique ID the worker assigns itself (e.g. hostname + PID). Persisted on the `connectors` row as `current_worker_id`. |

---

## HelloAck

Hub's reply after it validates the JWT and reads the connector's settings.
Carries negotiated capacity limits; the worker must respect them.

```proto
message HelloAck {
  string connector_id             = 1;
  string organization_id          = 2;
  string namespace                = 3;
  uint32 max_agents               = 4;
  uint32 max_tools_per_agent      = 5;
  uint32 max_concurrent_tool_calls = 6;
}
```

| Field | Type | Description |
|---|---|---|
| `connector_id` | string | Numeric connector row ID as a string. |
| `organization_id` | string | Owning organization row ID as a string. |
| `namespace` | string | Connector's declared namespace (e.g. `magento`). All agent/tool keys must be prefixed with `<namespace>.`. |
| `max_agents` | uint32 | Maximum agents this connector may declare. |
| `max_tools_per_agent` | uint32 | Maximum tools per agent. |
| `max_concurrent_tool_calls` | uint32 | How many `ToolCallRequest` frames the hub will send simultaneously before back-pressuring. |

---

## Register

Declarative snapshot of the connector's current agents. Replaces any prior
baseline. The worker sends this after `HelloAck` and any time its agent/tool
declarations change.

```proto
message Register {
  string             baseline_fingerprint = 1;  // sha256 of canonical(agents)
  repeated AgentDecl agents               = 2;
}
```

| Field | Type | Description |
|---|---|---|
| `baseline_fingerprint` | string | SHA-256 of the canonical form of `agents`. If the hub's in-memory cache already holds this fingerprint for the connector, reconciliation is skipped and the hub returns `RegisterAck{accepted}` immediately without touching the database. |
| `agents` | repeated AgentDecl | Full current agent list. Agents absent from this list are marked `inactive` by the hub. |

---

## RegisterAck

Hub's reply to `Register`. On acceptance, hub has persisted the baseline.
On rejection, the full list of validation issues is returned; the connector
keeps its stream open and may retry after fixing the issues.

```proto
message RegisterAck {
  string             baseline_fingerprint = 1;
  string             status               = 2;  // "accepted" | "rejected"
  repeated DeclIssue issues               = 3;
}
```

| Field | Type | Description |
|---|---|---|
| `baseline_fingerprint` | string | Echoes the fingerprint from the `Register` frame. |
| `status` | string | `"accepted"` or `"rejected"`. |
| `issues` | repeated DeclIssue | Non-empty only when `status == "rejected"`. Each entry describes one validation failure. |

---

## AgentDecl

One agent declaration inside a `Register` frame.

```proto
message AgentDecl {
  string               key          = 1;
  string               name         = 2;
  string               description  = 3;
  string               status       = 4;
  ModelDecl            model        = 5;
  repeated InstructionDecl instructions = 6;
  repeated ToolDecl    tools        = 7;
}
```

| Field | Type | Constraints |
|---|---|---|
| `key` | string | Must start with `<namespace>.`. Unique within the organization. |
| `name` | string | Human-readable label shown in the admin UI. |
| `description` | string | Optional. |
| `status` | string | `"active"` or `"inactive"`. |
| `model` | ModelDecl | Required. Defines the LLM backend for this agent. |
| `instructions` | repeated InstructionDecl | Ordered list; position is explicit in each entry. |
| `tools` | repeated ToolDecl | Tools this agent exposes. Count must not exceed `HelloAck.max_tools_per_agent`. Each key must start with `<namespace>.`. |

---

## ModelDecl

```proto
message ModelDecl {
  string                  provider = 1;
  string                  name     = 2;
  google.protobuf.Struct  config   = 3;
}
```

| Field | Type | Description |
|---|---|---|
| `provider` | string | Provider identifier (e.g. `anthropic`, `openai`). Must be one the runtime supports. |
| `name` | string | Model name (e.g. `claude-sonnet-4-5`). |
| `config` | Struct | Optional extra parameters (temperature, max_tokens, etc.) as a JSON object. |

---

## InstructionDecl

One system-prompt block. Multiple blocks are composed in `position` order.

```proto
message InstructionDecl {
  string type     = 1;
  string format   = 2;
  string body     = 3;
  uint32 position = 4;
}
```

| Field | Type | Values |
|---|---|---|
| `type` | string | `"system"`, `"task"`, `"persona"`, `"safety"` |
| `format` | string | `"markdown"`, `"jinja"`, `"plain"` |
| `body` | string | The instruction text. |
| `position` | uint32 | Ascending sort order. |

---

## ToolDecl

One tool definition. The connector owns the tool's schema; admins can
disable a tool but cannot modify its schema.

```proto
message ToolDecl {
  string key                = 1;
  string name               = 2;
  string description        = 3;
  bytes  input_schema_json  = 4;
  bytes  output_schema_json = 5;
  uint32 default_deadline_ms = 6;
  uint32 max_result_bytes   = 7;
}
```

| Field | Type | Description |
|---|---|---|
| `key` | string | Must start with `<namespace>.`. Unique within the agent. |
| `name` | string | Human-readable tool name. |
| `description` | string | Shown to the LLM and in the admin UI. |
| `input_schema_json` | bytes | Raw JSON Schema document. Validated by the hub at registration. Sent as `bytes` to avoid UTF-8 validation overhead. |
| `output_schema_json` | bytes | Raw JSON Schema document. Hub validates `result_json` against this on every tool call response. |
| `default_deadline_ms` | uint32 | How long the hub waits for a `ToolCallResponse` before timing out. `0` → hub default (30 000 ms). |
| `max_result_bytes` | uint32 | Maximum byte length of `result_json`. `0` → hub default (1 MiB = 1 048 576 bytes). |

---

## DeclIssue

Returned inside `RegisterAck.issues` when `status == "rejected"`.

```proto
message DeclIssue {
  string path    = 1;
  string code    = 2;
  string message = 3;
}
```

| Field | Description |
|---|---|
| `path` | JSONPath-style pointer to the failing field (e.g. `agents[0].tools[2].input_schema_json`). |
| `code` | Machine-readable code: `schema_invalid`, `namespace_violation`, `max_agents_exceeded`, `max_tools_per_agent_exceeded`, `marshal_error`, `internal_error`. |
| `message` | Human-readable explanation. |

---

## Heartbeat

Worker sends this every 20 s (the PHP SDK's `HeartbeatTimer` default).
The hub resets its idle-timeout timer on receipt.

```proto
message Heartbeat {
  google.protobuf.Timestamp at = 1;
}
```

| Field | Description |
|---|---|
| `at` | Worker-side timestamp. Used for drift diagnostics; not a liveness gate. |

---

## HeartbeatAck

```proto
message HeartbeatAck {
  google.protobuf.Timestamp at = 1;
}
```

| Field | Description |
|---|---|
| `at` | Hub-side timestamp at the moment the heartbeat was processed. |

---

## ToolCallRequest

Hub dispatches this when the runtime needs a connector-hosted tool result.
The worker must respond with a matching `ToolCallResponse` within
`deadline_ms` milliseconds.

```proto
message ToolCallRequest {
  string invocation_id   = 1;
  string agent_key       = 2;
  string tool_key        = 3;
  bytes  args_json       = 4;
  string organization_id = 5;
  string user_id         = 6;
  string conversation_id = 7;
  uint32 deadline_ms     = 8;
  string user_email      = 9;
}
```

| Field | Type | Description |
|---|---|---|
| `invocation_id` | string | Hub-minted UUID; must be echoed in `ToolCallResponse.invocation_id`. |
| `agent_key` | string | The agent that owns the tool. |
| `tool_key` | string | The tool to invoke. |
| `args_json` | bytes | JSON-encoded arguments. The hub validates these against `ToolDecl.input_schema_json` before sending; the worker receives only valid args. |
| `organization_id` | string | Owning organization. |
| `user_id` | string | ID of the user whose session triggered the agent run. Empty for system/scheduled runs. |
| `conversation_id` | string | Conversation the run belongs to. |
| `deadline_ms` | uint32 | Worker must respond within this many milliseconds. Mirrors `ToolDecl.default_deadline_ms` (or the hub default of 30 000 ms). |
| `user_email` | string | PII. Empty when no human user. Do not log or persist beyond the scope of the in-flight call. |

---

## ToolCallResponse

Worker's reply to a `ToolCallRequest`.

```proto
message ToolCallResponse {
  string invocation_id = 1;
  oneof result {
    bytes  result_json = 2;
    string error       = 3;
  }
  uint32 duration_ms = 4;
}
```

| Field | Type | Description |
|---|---|---|
| `invocation_id` | string | Must match the `ToolCallRequest.invocation_id`. |
| `result_json` | bytes (oneof) | JSON-encoded result. Hub validates against `ToolDecl.output_schema_json` and enforces `max_result_bytes`. |
| `error` | string (oneof) | Connector-side error message. When set, `result_json` is ignored and the hub surfaces the error to the calling LLM. |
| `duration_ms` | uint32 | Wall time the worker spent processing. Stored in telemetry. |

When `error` is non-empty, `result_json` must not be set (they are a `oneof`).
When `result_json` is set, it must be valid JSON matching the tool's declared
`output_schema_json`; the hub rejects non-conforming responses with
`tool_call_invalid_result`.

---

## GoAway

Hub asks the worker to disconnect. The worker should close its end of the
stream after receiving this frame, then behave according to the reason below.

```proto
message GoAway {
  string reason = 1;
}
```

| `reason` | Trigger | Worker action |
|---|---|---|
| `token_rotated` | Admin rotated the connector's token. | Exit (code 78). Restart with the new token; the supervisor's backoff reconnects promptly. |
| `revoked` | Admin revoked the connector. | Exit (code 78). Do not retry; the token is permanently invalid. |
| `hub_draining` | Hub instance is shutting down gracefully. | Reconnect; the supervisor's backoff will land on the new hub instance. |
| `idle` | No frames received for `idle_timeout_s` (default 30 s). | Reconnect normally. |

---

## Next

[Authentication](auth.md)
