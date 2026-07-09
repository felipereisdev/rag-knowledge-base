#!/usr/bin/env sh
# Cursor sessionStart: ensure project; optionally inject digest as additional_context.
DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
RAG_HOOK_DIR="$DIR"
. "$DIR/lib/rag-core.sh" 2>/dev/null
rag_load_config

CWD="${CURSOR_PROJECT_DIR:-$(pwd)}"
rag_ensure_project "$CWD" >/dev/null 2>&1

if [ "$RAG_HOOK_INJECT_ON_START" = "true" ]; then
  DIGEST=$(rag_digest "$CWD")
  if [ -n "$DIGEST" ]; then
    python3 -c 'import json,sys; print(json.dumps({"additional_context":"Approved knowledge:\n"+sys.argv[1]}))' "$DIGEST"
    exit 0
  fi
fi
echo '{}'
exit 0
