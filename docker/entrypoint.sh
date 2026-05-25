#!/usr/bin/env sh
# Placeholder for any pre-flight setup the container needs.
# Currently a no-op; ENTRYPOINT in Dockerfile goes straight to bin/vested-connect.
set -e
exec "$@"
