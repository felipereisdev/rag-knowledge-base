#!/usr/bin/env sh
# Smoke-test the Claude adapters against a running backend.
# Usage: RAG_HOOK_URL=http://localhost:8090 RAG_HOOK_TOKEN=xxx ./bin/test-hooks.sh /path/to/client
set -e
CLIENT="${1:-.}"
H="$CLIENT/.claude/hooks"

echo "== session-start =="
CLAUDE_PROJECT_DIR="$CLIENT" sh "$H/session-start.sh" || true

echo "== user-prompt =="
printf '{"prompt":"how does auth scoping work here"}' | CLAUDE_PROJECT_DIR="$CLIENT" sh "$H/user-prompt.sh" || true

echo "== stop (should emit decision:block) =="
printf '{"stop_hook_active":false}' | sh "$H/stop.sh" || true

echo "== stop (loop guard, should be empty) =="
printf '{"stop_hook_active":true}' | sh "$H/stop.sh" || true
