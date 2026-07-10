#!/usr/bin/env sh
# Shared RAG hook core. Sourced by per-harness adapters.
# All network calls fail silently so a hook never breaks a session.

rag_load_config() {
  RAG_HOOK_DIR="${RAG_HOOK_DIR:-$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)}"
  if [ -f "$RAG_HOOK_DIR/config.sh" ]; then
    . "$RAG_HOOK_DIR/config.sh"
  fi
  : "${RAG_HOOK_URL:=http://localhost:8090}"
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

# Fire-and-forget: ask the worker to condense a finished session.
rag_condense_post() {
  _cwd=$(_rag_json_escape "$1")
  _sid=$(_rag_json_escape "$2")
  _tp=$(_rag_json_escape "$3")
  _rag_post "condense" "{\"cwd\": ${_cwd}, \"session_id\": ${_sid}, \"transcript_path\": ${_tp}}"
}
