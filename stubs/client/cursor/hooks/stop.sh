#!/usr/bin/env sh
# Cursor stop: auto-submit a condensation follow-up once (loop_count guard).
DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
RAG_HOOK_DIR="$DIR"
. "$DIR/lib/rag-core.sh" 2>/dev/null
rag_load_config

if [ "$RAG_HOOK_CONDENSE" != "true" ]; then echo '{}'; exit 0; fi

INPUT=$(cat)
LOOP=$(printf '%s' "$INPUT" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("loop_count",0))' 2>/dev/null)
[ -z "$LOOP" ] && LOOP=0
if [ "$LOOP" -ge 1 ]; then echo '{}'; exit 0; fi

REASON=$(rag_condense_instruction)
python3 -c 'import json,sys; print(json.dumps({"followup_message":sys.argv[1]}))' "$REASON"
exit 0
