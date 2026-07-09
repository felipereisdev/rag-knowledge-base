#!/usr/bin/env sh
# Claude Code Stop: nudge the current session to condense (once).
DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
RAG_HOOK_DIR="$DIR"
. "$DIR/lib/rag-core.sh" 2>/dev/null
rag_load_config

[ "$RAG_HOOK_CONDENSE" != "true" ] && exit 0

INPUT=$(cat)
ACTIVE=$(printf '%s' "$INPUT" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("stop_hook_active", False))' 2>/dev/null)
# Loop guard: if we already blocked once this turn, let it stop.
[ "$ACTIVE" = "True" ] && exit 0

REASON=$(rag_condense_instruction)
python3 -c 'import json,sys; print(json.dumps({"decision":"block","reason":sys.argv[1]}))' "$REASON"
exit 0
