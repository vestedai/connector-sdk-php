#!/usr/bin/env bash
# Copy the canonical proto to the SDK's local proto/ and rewrite the
# two php_namespace options so the generated stubs land under the SDK's
# namespace instead of laravel's.
#
# Run: ./vested-ai-sdks/php/scripts/sync-proto.sh
# Idempotent; safe to re-run on every CI invocation.

set -euo pipefail
cd "$(dirname "$0")/.."

SRC=../../proto/vested/v1/connector_hub.proto
DST=proto/vested/v1/connector_hub.proto

mkdir -p "$(dirname "$DST")"

# Copy + rewrite the two namespace options. sed -i handles macOS + GNU
# via the `''` empty-suffix trick.
cp "$SRC" "$DST"

# macOS sed needs '' after -i; GNU sed doesn't. Detect.
if sed --version >/dev/null 2>&1; then
  SED_INPLACE=(sed -i)
else
  SED_INPLACE=(sed -i '')
fi

"${SED_INPLACE[@]}" \
  -e 's|^option php_namespace .*|option php_namespace            = "Vested\\\\Connect\\\\Sdk\\\\Generated\\\\Proto\\\\Vested\\\\V1";|' \
  -e 's|^option php_metadata_namespace .*|option php_metadata_namespace   = "Vested\\\\Connect\\\\Sdk\\\\Generated\\\\Proto\\\\GPBMetadata";|' \
  "$DST"

echo "synced: $DST"
echo "verify with: grep php_namespace $DST"
