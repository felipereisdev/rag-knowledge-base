<?php

use App\Services\Importance\ImportancePrompt;

return [
    'embeddings' => [
        'provider' => env('RAG_EMBEDDING_PROVIDER', 'local-embedder'),
        'model' => env('RAG_EMBEDDING_MODEL', 'paraphrase-multilingual-mpnet-base-v2'),
        'dimension' => (int) env('RAG_EMBEDDING_DIM', 768),
    ],
    'search' => [
        'min_score' => (float) env('RAG_SEARCH_MIN_SCORE', 0.30),
        'limit' => (int) env('RAG_SEARCH_LIMIT', 10),
        'rrf_k' => (int) env('RAG_SEARCH_RRF_K', 60),
        'graph_expand' => (bool) env('RAG_SEARCH_GRAPH_EXPAND', true),
        'graph_weight' => (float) env('RAG_SEARCH_GRAPH_WEIGHT', 0.3),
        'vector_top_k' => (int) env('RAG_SEARCH_VECTOR_TOP_K', 20),
        'fts_top_k' => (int) env('RAG_SEARCH_FTS_TOP_K', 20),
    ],
    'hooks' => [
        'token' => env('RAG_HOOK_TOKEN', ''),
    ],

    // Fixed, code-owned defaults for the hybrid importance classifier's
    // semantic judge. Only `mode` and `threshold` are administrator-editable
    // (via the ImportanceClassifierSetting singleton, added in Task 4/7);
    // everything below is versioned in code so historical assessments stay
    // attributable to the exact model/prompt/rules that produced them.
    'importance' => [
        'model' => env('RAG_IMPORTANCE_MODEL', 'claude-haiku-4-5-20251001'),
        'timeout' => (int) env('RAG_IMPORTANCE_TIMEOUT', 90),
        'prompt_version' => ImportancePrompt::VERSION,
        'rules_version' => 'v1',
        'max_reason_count' => (int) env('RAG_IMPORTANCE_MAX_REASON_COUNT', 5),
        'max_reason_length' => (int) env('RAG_IMPORTANCE_MAX_REASON_LENGTH', 280),
        'stale_after_minutes' => (int) env('RAG_IMPORTANCE_STALE_AFTER_MINUTES', 15),
        'queue' => env('RAG_IMPORTANCE_QUEUE', 'classification'),
    ],
];
