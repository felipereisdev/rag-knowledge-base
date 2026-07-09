#!/usr/bin/env sh
# Shared RAG hook core. Sourced by per-harness adapters.
# All network calls fail silently so a hook never breaks a session.

rag_load_config() {
  RAG_HOOK_DIR="${RAG_HOOK_DIR:-$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)}"
  if [ -f "$RAG_HOOK_DIR/config.sh" ]; then
    . "$RAG_HOOK_DIR/config.sh"
  fi
  : "${RAG_HOOK_URL:=http://localhost:8080}"
  : "${RAG_HOOK_TOKEN:=}"
  : "${RAG_HOOK_INJECT_ON_START:=false}"
  : "${RAG_HOOK_SEARCH_MIN_SCORE:=0.40}"
  : "${RAG_HOOK_SEARCH_LIMIT:=3}"
  : "${RAG_HOOK_CONDENSE:=true}"
}

# POST JSON to a /hooks endpoint; echo the text body, or nothing on failure.
_rag_post() {
  _endpoint="$1"
  _body="$2"
  curl -fsS --max-time 4 \
    -H "Authorization: Bearer ${RAG_HOOK_TOKEN}" \
    -H "Content-Type: application/json" \
    -X POST "${RAG_HOOK_URL}/hooks/${_endpoint}" \
    -d "$_body" 2>/dev/null || true
}

# JSON-escape a string value (quotes, backslashes, newlines).
_rag_json_escape() {
  printf '%s' "$1" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))'
}

rag_ensure_project() {
  _cwd=$(_rag_json_escape "$1")
  _rag_post "ensure-project" "{\"cwd\": ${_cwd}}"
}

rag_digest() {
  _cwd=$(_rag_json_escape "$1")
  _rag_post "digest" "{\"cwd\": ${_cwd}}"
}

rag_search() {
  _cwd=$(_rag_json_escape "$1")
  _q=$(_rag_json_escape "$2")
  _rag_post "search" "{\"cwd\": ${_cwd}, \"query\": ${_q}, \"limit\": ${RAG_HOOK_SEARCH_LIMIT}, \"min_score\": ${RAG_HOOK_SEARCH_MIN_SCORE}}"
}

rag_condense_instruction() {
  cat <<'EOF'
Before you finish: judge whether this session produced durable knowledge (a decision, rule, architecture note, non-obvious fix, or convention). If not, stop normally. If yes: first call rag_search to check it is not already stored (dedup); then condense it into one or more knowledge entries — each with a clear title, Markdown content, a category, and any salient entities/relations — and call rag_store_knowledge (it lands in pending for review). Then stop.
EOF
}
