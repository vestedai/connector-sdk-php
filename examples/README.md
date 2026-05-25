# Examples

| File | Style | What it demonstrates |
|---|---|---|
| `minimal-builder.php` | Chained builder + closure | The smallest useful connector: one agent, one tool. ~30 lines. |
| `magento-class-based/` | Attribute-decorated classes + reflection scanner | A multi-tool, multi-instruction connector wired through a PSR-11 container. |

Run from the SDK root:

```bash
VESTED_CONNECTOR_TOKEN=$(your-token-source) \
  vendor/bin/vested-connect worker --bootstrap=examples/minimal-builder.php
```
