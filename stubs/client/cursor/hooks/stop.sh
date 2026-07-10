#!/usr/bin/env sh
# Cursor stop: fire-and-forget condense request to the RAG worker.
DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
RAG_HOOK_DIR="$DIR"
. "$DIR/lib/rag-core.sh" 2>/dev/null
rag_load_config

if [ "$RAG_HOOK_CONDENSE" != "true" ]; then echo '{}'; exit 0; fi

INPUT=$(cat)
CWD=$(printf '%s' "$INPUT" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("cwd",""))' 2>/dev/null)
SID=$(printf '%s' "$INPUT" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("session_id",""))' 2>/dev/null)
TP=$(printf '%s' "$INPUT" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("transcript_path",""))' 2>/dev/null)

if [ -n "$TP" ]; then
  [ -z "$CWD" ] && CWD="${CLAUDE_PROJECT_DIR:-$(pwd)}"
  rag_condense_post "$CWD" "$SID" "$TP" >/dev/null 2>&1 &
fi
echo '{}'
exit 0
