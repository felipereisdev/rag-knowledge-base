#!/usr/bin/env sh
# Codex UserPromptSubmit: inject RAG hits as additionalContext.
DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
RAG_HOOK_DIR="$DIR"
. "$DIR/lib/rag-core.sh" 2>/dev/null
rag_load_config

INPUT=$(cat)
PROMPT=$(printf '%s' "$INPUT" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("prompt",""))' 2>/dev/null)
CWD=$(printf '%s' "$INPUT" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("cwd",""))' 2>/dev/null)
[ -z "$CWD" ] && CWD="$(pwd)"
[ "${#PROMPT}" -lt 8 ] && exit 0

HITS=$(rag_search "$CWD" "$PROMPT")
[ -z "$HITS" ] && exit 0
python3 -c 'import json,sys; print(json.dumps({"hookSpecificOutput":{"hookEventName":"UserPromptSubmit","additionalContext":"Relevant prior knowledge (RAG):\n"+sys.argv[1]}}))' "$HITS"
exit 0
