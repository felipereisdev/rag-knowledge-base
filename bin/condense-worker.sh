#!/usr/bin/env sh
# Start the condense queue worker where the Martis driver says it should run:
#   driver=claude_sdk -> locally on this host (reuses the host's authenticated
#                        `claude` CLI; no API key), like claude-mem
#   driver=api        -> in Docker (the rag-worker service, uses an API key)
# The driver is configured in Martis (Condense Settings); this script derives
# the placement from it — there is no separate env var.
set -e

DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$DIR"

DRIVER=$(php artisan rag:condense-driver -n --no-ansi 2>/dev/null | tr -d '[:space:]')

case "$DRIVER" in
  claude_sdk)
    if ! command -v claude >/dev/null 2>&1; then
      echo "driver=claude_sdk but 'claude' is not on PATH." >&2
      echo "Install & authenticate Claude Code on this host, or switch the driver to 'api' in Martis." >&2
      exit 1
    fi
    echo "driver=claude_sdk -> running the queue worker locally (host claude auth)."
    exec php artisan queue:work --queue=condense --tries=3 --sleep=3
    ;;
  api)
    echo "driver=api -> starting the dockerized worker."
    exec docker compose --profile condense up -d worker
    ;;
  *)
    echo "Unknown or empty condense driver: '${DRIVER}'." >&2
    echo "Set the driver in Martis (Condense Settings) and ensure the DB is reachable from this host." >&2
    exit 1
    ;;
esac
