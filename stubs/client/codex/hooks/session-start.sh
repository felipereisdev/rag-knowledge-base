#!/usr/bin/env sh
# Codex SessionStart: ensure project; optionally inject digest as additionalContext.
DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
RAG_HOOK_DIR="$DIR"
. "$DIR/lib/rag-core.sh" 2>/dev/null
rag_load_config

INPUT=$(cat)
CWD=$(printf '%s' "$INPUT" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("cwd",""))' 2>/dev/null)
[ -z "$CWD" ] && CWD="$(pwd)"

rag_ensure_project "$CWD" >/dev/null 2>&1

[ "$RAG_HOOK_INJECT_ON_START" != "true" ] && exit 0
DIGEST=$(rag_digest "$CWD")
[ -z "$DIGEST" ] && exit 0
python3 -c 'import json,sys; print(json.dumps({"hookSpecificOutput":{"hookEventName":"SessionStart","additionalContext":"Approved knowledge:\n"+sys.argv[1]}}))' "$DIGEST"
exit 0
