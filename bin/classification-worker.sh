#!/usr/bin/env sh
# Start the importance-classification queue worker on THIS host.
#
# The classifier judges a candidate by shelling out to the host's authenticated
# `claude` CLI (no API key), like the condense worker in claude_sdk mode. The
# production Docker image ships no `claude` binary, so this worker must run on a
# trusted host where Claude Code is installed and logged in — never in the app
# container.
#
# The invocation below is exact. `classification` appears twice on purpose:
#
#   php artisan queue:work classification --queue=classification ...
#                          ^ CONNECTION      ^ queue name
#
# The connection argument is load-bearing. ClassifyKnowledgeEntryJob has a 120s
# $timeout (90s Claude timeout + 30s margin), and only the dedicated
# `classification` connection carries a retry_after (150s) above it. Drop the
# connection argument and the worker reserves the very same rows under the
# DEFAULT connection's 90s retry_after — below the job's own timeout — so the
# queue re-delivers a classification that is still in flight, a second worker
# burns the attempt counter, and the real verdict is discarded in favour of a
# fabricated `assessment_in_progress` failure. The required ordering is:
#
#   Claude process timeout (90s) < job $timeout (120s) < retry_after (150s)
#
set -e

DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$DIR"

if ! command -v claude >/dev/null 2>&1; then
  echo "'claude' is not on PATH." >&2
  echo "The importance classifier calls the host's authenticated Claude Code CLI, and the" >&2
  echo "production image does not provide it. Install and authenticate Claude Code on this" >&2
  echo "host, or leave the classifier's mode set to 'off' in Martis." >&2
  exit 1
fi

echo "Starting the classification worker (host claude auth) on the 'classification' connection."
exec php artisan queue:work classification --queue=classification --tries=3 --timeout=120
