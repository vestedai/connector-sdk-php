# PHP SDK Documentation

## Get Started

- [Quickstart](quickstart.md) — 15-minute walkthrough: install, declare an agent + tool, run the worker, verify in the admin UI
- [Concepts](concepts.md) — mental model: agents, tools, instructions, baselines, overrides, inheritance state machine

## Reference

- [API reference](api.md) — `ConnectorApp`, `AgentBuilder`, attributes, `ToolHandler`, `ToolContext`, container integration

## Operate

- [Operations](operations.md) — Docker, environment variables, observability, reconnect supervisor, DB pool sizing, known gotchas, troubleshooting
- [Upgrading](upgrading.md) — v0.1 → v0.2 migration guide; v0.2.x patch notes

## Connector Protocol

- [Protocol overview](protocol/overview.md)
- [Wire format](protocol/wire.md)
- [Override mechanics](protocol/overrides.md)
- [Reconciliation algorithm](protocol/reconciliation.md)
- [Connection lifecycle](protocol/lifecycle.md)

## Examples

- [magento-class-based](../examples/magento-class-based/) — full working example: Magento product-search agent using the attribute API
