# stubs/client/hooks/config.sh
# RAG hook configuration. Values baked in by `php artisan rag:install`.
RAG_HOOK_URL="__RAG_URL__"
RAG_HOOK_TOKEN="__RAG_TOKEN__"

# Opt-in: inject a digest of approved knowledge at session start.
RAG_HOOK_INJECT_ON_START="false"

# Auto-search tuning.
RAG_HOOK_SEARCH_MIN_SCORE="0.40"
RAG_HOOK_SEARCH_LIMIT="3"

# Enable the end-of-session condensation nudge.
RAG_HOOK_CONDENSE="true"
