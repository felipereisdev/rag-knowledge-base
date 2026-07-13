<?php

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
];
