# Protocol Overview

This section describes the binary wire protocol between a connector worker
and the hub. It is language-neutral reference material aimed at engineers
implementing a non-PHP SDK (Python, Node, Go, etc.) or operators debugging
the wire format. Familiarity with gRPC and protobuf3 is assumed.

---

## The wire

One bidirectional gRPC stream per connected worker, served by the
`ConnectorHub.Connect` RPC:

```proto
service ConnectorHub {
  rpc Connect(stream ConnectorMsg) returns (stream HubMsg);
}
```

Transport: HTTP/2 + TLS. Default port: **4443** (configurable via runtime
environment). The proto package is `vested.v1`; the canonical source file is
`proto/vested/v1/connector_hub.proto`.

**Frame encoding** follows standard gRPC framing:

```
┌─────────────────────────────────────────────────────────┐
│  1 byte  │ 4 bytes (big-endian) │ N bytes               │
│  compression flag (0 = none)    │ serialized message    │
└─────────────────────────────────────────────────────────┘
```

- Workers send `ConnectorMsg` frames.
- The hub sends `HubMsg` frames.
- Both messages use a `oneof body` to multiplex all frame types over the
  single stream.

---

## The flow at a glance

```
Worker                       Hub
  │── open stream ───────────►│
  │── Hello ─────────────────►│
  │                           │◄── HelloAck (connector_id, namespace,
  │                           │             negotiated caps)
  │── Register ──────────────►│   (agents[], tools, fingerprint)
  │                           │◄── RegisterAck (accepted | rejected)
  │── Heartbeat (every 20 s) ►│
  │                           │◄── HeartbeatAck
  │                           │◄── ToolCallRequest
  │── ToolCallResponse ───────►│
  │                           │◄── GoAway (on shutdown / rotation / idle)
```

The handshake sequence (`Hello` → `HelloAck` → `Register` → `RegisterAck`)
must complete within 10 s of stream open. After that the stream enters the
steady-state frame loop.

---

## Who initiates what

| Initiator | Messages |
|---|---|
| Worker | `Hello`, `Register`, `ToolCallResponse`, `Heartbeat` |
| Hub | `HelloAck`, `RegisterAck`, `ToolCallRequest`, `HeartbeatAck`, `GoAway` |

Workers always open the connection. The hub never dials out. The hub
initiates `ToolCallRequest` and `GoAway`; all other hub messages are direct
replies to worker frames.

---

## Frame size limits

The hub enforces `max_result_bytes` per tool (declared in `ToolDecl`;
default 1 MiB). There is no separate per-frame size cap beyond the standard
gRPC message size limit (4 MiB by default in most gRPC implementations).

Responses exceeding `max_result_bytes` are rejected at the hub with
`tool_call_invalid_result`; the connector is expected to truncate or
paginate its output.

---

## Versioning

The proto package is `vested.v1`. The design follows standard proto3
compatibility rules:

- **New fields** added to existing messages are non-breaking (unknown fields
  are ignored by older implementations).
- **New `oneof` variants** on `ConnectorMsg.body` or `HubMsg.body` are
  additive. Implementations that do not recognise a variant should ignore it
  (the frame's `body` will parse as unset / nil).
- **Field renaming or field number changes** are breaking and will be
  communicated with a version increment in the package name.

---

## Next

[Messages](messages.md)
