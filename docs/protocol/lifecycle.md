# Connection Lifecycle

---

## Handshake

The handshake must complete within **10 s** of stream open. The sequence is:

```
1. Worker opens the gRPC stream with x-connector-token in HTTP/2 metadata.
2. Hub validates the JWT (see Authentication).
   Any failure → Unauthenticated, stream closed.
3. Hub waits up to 5 s for the first frame.
   First frame must be Hello.
   No frame within 5 s → DeadlineExceeded, stream closed.
   Frame is not Hello → InvalidArgument, stream closed.
4. Hub sends HelloAck with the connector's negotiated caps.
5. Worker sends Register.
6. Hub reconciles the baseline and sends RegisterAck.
```

**What can block reconciliation:**

- Token verification: signature check + Redis revocation lookup.
- Namespace assertion: every `agent_key` and `tool_key` must be prefixed
  with `<namespace>.`.
- JSON Schema compilation: every `input_schema_json` and `output_schema_json`
  must be a valid JSON Schema document.
- Per-connector caps: agent count ≤ `max_agents`; tools per agent ≤
  `max_tools_per_agent`.

**`RegisterAck.status = "rejected"`** means one or more of the above checks
failed. The `issues[]` field on the ack enumerates every violation as a
`DeclIssue{path, code, message}`. The stream stays open; the worker may
correct the declarations and send a new `Register` frame.

**Fingerprint short-circuit:** If the hub's in-memory cache already holds the
same `baseline_fingerprint` for this connector, the hub replies
`RegisterAck{status: "accepted"}` immediately without any database I/O. This
is the common path on reconnect after a hub restart or transient network blip.

---

## Heartbeats

The worker sends a `Heartbeat` frame every **20 s** (the PHP SDK's
`HeartbeatTimer` default). The hub replies with `HeartbeatAck` and resets
its idle-timeout timer.

**Hub-side idle timer:** if the hub receives no frames from the worker for
**30 s** (`IdleTimeoutSeconds`, configurable via `ServerDeps`), it sends
`GoAway{reason: "idle"}` and closes the stream. The worker should reconnect
normally on receiving this.

**Offline threshold:** the spec defines `offline_threshold_s = 60 s`. After
60 s without a reconnect following a disconnect, the connector's status
transitions to "offline" in the admin UI. This is derived from
`last_disconnected_at` on the `connectors` row; there is no hub-side timer
that actively closes anything — the status is computed at query time.

**Soft-degraded wait:** immediately after a disconnect, if a new tool call
arrives, the hub waits up to **5 s** (`degradedWaitMS = 5000 ms`) for the
worker to reconnect before returning `connector_unavailable` to the calling
LLM. This covers brief network blips and hub-restart reconnects.

| Threshold | Value | Purpose |
|---|---|---|
| Hello deadline | 5 s | First frame must arrive within this window. |
| Idle timeout | 30 s (default) | No frames → `GoAway{idle}`. |
| Degraded wait | 5 s | New tool calls hold this long after a disconnect. |
| Offline threshold | 60 s | UI flips status to "offline" after this duration. |
| Heartbeat interval | 20 s | Worker-side send cadence (PHP SDK default). |

---

## Tool dispatch

1. The runtime calls into the hub's in-process dispatcher.
2. Hub validates `args_json` against the tool's `input_schema_json`. Invalid
   → `tool_call_invalid_args` returned to the runtime; the connector is
   never contacted.
3. Hub checks that the connector has a live stream. If absent and within the
   5 s degraded-wait window, the hub spins waiting for a reconnect.
4. Hub acquires a concurrency slot (semaphore bounded by
   `HelloAck.max_concurrent_tool_calls`). Full → `connector_busy`.
5. Hub mints a `invocation_id` (UUIDv7), registers a pending response
   channel, and sends `ToolCallRequest` on the stream.
6. Hub blocks until:
   - `ToolCallResponse` arrives with a matching `invocation_id`, or
   - `deadline_ms` elapses → `tool_call_timeout`, or
   - The stream closes → `connector_unavailable`.
7. Hub validates `result_json` against `output_schema_json` and checks
   `max_result_bytes`. Invalid → `tool_call_invalid_result`.
8. Hub releases the concurrency slot and returns the result to the runtime.

The worker must echo the same `invocation_id` in its `ToolCallResponse`.
Frames with an unrecognised `invocation_id` are silently dropped.

---

## Disconnect

Three disconnect modes are all normal; the supervisor handles reconnect for
transient cases.

| Mode | Cause | Hub action |
|---|---|---|
| Clean | Worker half-closes the stream (EOF). | Marks `last_disconnected_at`, emits `disconnect` audit event, clears stream map entry. |
| Graceful | Hub sends `GoAway` first. | Worker closes after receiving `GoAway`; hub then sees EOF. Same cleanup. |
| Abrupt | TCP reset, network blip, process crash. | Stream read returns an error; same cleanup. |

Pending in-flight tool calls are held for up to `degradedWaitMS` (5 s)
after the stream map entry is cleared, in case the worker reconnects quickly.
After that window, pending calls return `connector_unavailable`.

---

## Reconnect

The PHP SDK supervisor wraps the daemon in an exponential-backoff loop:

| Parameter | Value |
|---|---|
| Initial delay | 1 000 ms |
| Cap | 30 000 ms |
| Jitter | ±20% |
| Sequence | 1 s, 2 s, 4 s, 8 s, 16 s, 30 s, 30 s, … |

The backoff resets after a successful handshake (i.e. a hub deploy that
causes a mid-session reconnect retries quickly; a hub that has been down for
an hour backs off progressively).

**Terminal exits (no retry):**

- Exit code 78 (`EX_CONFIG`): received `GoAway{reason: "token_rotated"}` or
  `GoAway{reason: "revoked"}`. A configuration change is required before the
  worker can reconnect.
- Signal-driven exit (exit code 0 or signal): operator-initiated shutdown.

All other exits trigger a retry after the next backoff interval.

On reconnect, the worker sends `Hello` then `Register` with the current
`baseline_fingerprint`. If the fingerprint matches the hub's cached value,
reconciliation is skipped and normal operation resumes in one round trip.

---

## Hub draining

During a hub deployment, the hub sends `GoAway{reason: "hub_draining"}` to
every active stream. The hub then stops accepting new `ToolCallRequest`
dispatches and waits `drain_grace_s` (default 30 s) for in-flight tool calls
to complete before closing streams.

Workers that receive `GoAway{reason: "hub_draining"}` should reconnect
normally — the supervisor's backoff loop will land on the new hub instance.
This reason is not a terminal exit.

---

## Next

[Audit events](audit.md)
