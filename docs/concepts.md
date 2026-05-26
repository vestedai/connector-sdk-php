# Concepts

## Agents

An agent is the unit of work the LLM acts through. Each agent has a model (provider + name + config), a set of ordered instruction blocks, and a set of tools. The connector declares the baseline agent shape; the hub persists it and creates an `AgentVersion`.

An agent key must start with the connector's namespace (e.g., `magento.products`). Keys are stable identifiers — the admin UI uses them to surface the agent and its version history.

The first registration of an agent auto-publishes the resulting `AgentVersion`. Subsequent changes to the baseline produce a draft version that the admin must review and publish.

## Tools

A tool is a function definition the LLM may call. It carries:

- An input JSON Schema (validated before the connector ever sees the call).
- An output JSON Schema (validated before the result reaches the LLM).
- A `ToolHandler` implementation that does the actual work.
- A `deadlineMs` (default 30 000) and `maxResultBytes` (default 1 MiB).

Tool calls are request/response in v1. The handler receives decoded `$args` (already schema-validated) and a `ToolContext` carrying the caller's identity. It returns a plain PHP array; the hub validates the result against the output schema before passing it back to the runtime.

Tool keys must also be namespaced: `magento.products.search`, not `search`.

## Instructions

Instructions are prompt segments injected into the agent's system prompt at runtime. Each instruction has a `type` (`system`, `task`, `persona`, `safety`), a `position` (integer, ascending order), a `body`, and a `format` (`markdown`, `jinja`, `plain`).

At compose time, `SystemPromptComposer` iterates instructions in position order, resolves each one through the inheritance state (see below), and concatenates the results. Org-wide shared instructions append at the end.

## Baselines vs. Overrides

The connector owns the **baseline**: the canonical instruction bodies, tool schemas, and model selection. The admin can **override** any instruction body or disable any tool, but cannot modify tool schemas directly (the connector owns the execution contract).

When the connector connects and sends a `Register` frame, the hub computes a fingerprint over the entire declaration. If the fingerprint matches the stored baseline, registration is a no-op (common on reconnect). If it differs, the hub calls Laravel's `ConnectorRegistry::reconcile()`, which creates a new `connector_baseline_*` snapshot and — for existing agents — creates a new draft `AgentVersion` with overrides re-applied on top of the new baseline.

## Inheritance State Machine

Each instruction pivot row (`agent_instructions`) carries an `inheritance_state` that controls what the composer uses at runtime:

```
                  ┌─────────────────────────────────────────┐
   first push     │                                         │
   ──────────►  inherit ──── admin edits ──►  replaced     │
                  │                                         │
                  │          admin disables ►  disabled     │
                  │                                         │
                  │          admin adds new ►  admin_added  │
                  │                                         │
                  └── connector drops position ► orphaned  │
                                                            │
                  (new baseline push re-links all states    │
                   except orphaned)                         │
                  └─────────────────────────────────────────┘
```

**`inherit`** — The admin has not touched this row. At compose time, the runtime reads the body from `connector_baseline_instructions`. This is the default state for every instruction on first registration.

**`replaced`** — The admin wrote a custom body. At compose time, the runtime uses the admin's `instructions.body` instead of the baseline. The `baseline_instruction_id` is preserved so the composer knows which position this replaces; on the next baseline push it re-links to the new baseline row at the same position.

**`disabled`** — The admin suppressed this instruction. The composer skips the position entirely. The baseline row still exists; re-enabling restores inherit.

**`admin_added`** — The admin added an instruction with no baseline counterpart (a new position the connector never declared). At compose time, treated like any other instruction. Not touched by reconciliation.

**`orphaned`** — A `replaced` or `disabled` override remains, but the connector no longer declares a baseline at this position. The composer skips orphaned rows. The admin UI surfaces them as warnings; the admin can delete or reassign.

## Reconciliation

When the connector pushes a new baseline (different fingerprint):

1. Hub short-circuits on fingerprint match — no Laravel hop.
2. On mismatch, Laravel `ConnectorRegistry::reconcile()` runs in a transaction.
3. For each agent in the new baseline, if an `Agent` row already exists:
   - A new draft `AgentVersion` is created.
   - Existing overrides re-link to the new baseline at the same position.
   - Positions that vanished from the baseline become `orphaned`.
   - The draft is **not** auto-published — the admin reviews first.
4. For each agent absent from the new baseline, `Agent.status` flips to `inactive`.
5. New agents in the baseline are created and published immediately.

## Versioning

Every baseline change that affects an existing agent produces a new `AgentVersion`. The version is in "draft" state until an admin publishes it. The published version is what the runtime uses for agent runs. Version history is preserved indefinitely in the database.

## Next

[API reference](api.md)
