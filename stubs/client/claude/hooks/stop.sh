#!/usr/bin/env sh
# Claude Code Stop: fire-and-forget condense request to the RAG worker.
DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
RAG_HOOK_DIR="$DIR"
. "$DIR/lib/rag-core.sh" 2>/dev/null
rag_load_config

[ "$RAG_HOOK_CONDENSE" != "true" ] && exit 0

INPUT=$(cat)
CWD=$(printf '%s' "$INPUT" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("cwd",""))' 2>/dev/null)
SID=$(printf '%s' "$INPUT" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("session_id",""))' 2>/dev/null)
TP=$(printf '%s' "$INPUT" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("transcript_path",""))' 2>/dev/null)

# Nothing to condense without a transcript.
[ -z "$TP" ] && exit 0
[ -z "$CWD" ] && CWD="${CLAUDE_PROJECT_DIR:-$(pwd)}"

# Detach so the session is never blocked (fire-and-forget).
rag_condense_post "$CWD" "$SID" "$TP" >/dev/null 2>&1 &
exit 0
