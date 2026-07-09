#!/usr/bin/env sh
# Claude Code SessionStart: ensure project exists; optionally inject digest.
DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
RAG_HOOK_DIR="$DIR"
. "$DIR/lib/rag-core.sh" 2>/dev/null
rag_load_config

CWD="${CLAUDE_PROJECT_DIR:-$(pwd)}"
rag_ensure_project "$CWD" >/dev/null 2>&1

if [ "$RAG_HOOK_INJECT_ON_START" = "true" ]; then
  DIGEST=$(rag_digest "$CWD")
  if [ -n "$DIGEST" ]; then
    printf 'Approved knowledge for this project (search the RAG for details):\n%s\n' "$DIGEST"
  fi
fi
exit 0
