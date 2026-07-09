#!/usr/bin/env sh
# Claude Code UserPromptSubmit: inject relevant RAG hits (stdin is hook JSON).
DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
RAG_HOOK_DIR="$DIR"
. "$DIR/lib/rag-core.sh" 2>/dev/null
rag_load_config

INPUT=$(cat)
PROMPT=$(printf '%s' "$INPUT" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("prompt",""))' 2>/dev/null)
CWD="${CLAUDE_PROJECT_DIR:-$(pwd)}"

# Skip very short prompts to reduce noise.
[ "${#PROMPT}" -lt 8 ] && exit 0

HITS=$(rag_search "$CWD" "$PROMPT")
if [ -n "$HITS" ]; then
  printf 'Relevant prior knowledge (from RAG):\n%s\n' "$HITS"
fi
exit 0
