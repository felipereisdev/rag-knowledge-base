<?php

use App\Jobs\ClassifyKnowledgeEntryJob;
use App\Services\Importance\DeterministicImportanceRules;
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
        'timeout' => (int) env('RAG_IMPORTANCE_TIMEOUT', ClassifyKnowledgeEntryJob::DEFAULT_MODEL_TIMEOUT_SECONDS),
        // DISPLAY ONLY (rag_status, the Martis setting screen). These two are
        // deliberately NOT load-bearing: `config:cache` snapshots them, and the
        // `bootstrap/cache` volume can outlive the image, so a cached value can
        // lag the code. Everything that stamps or keys on a version (the cache
        // identity in HybridImportanceClassifier, the audit record in
        // ClassifyKnowledgeEntryJob) reads the class constant directly instead.
        'prompt_version' => ImportancePrompt::VERSION,
        'rules_version' => DeterministicImportanceRules::VERSION,
        'max_reason_count' => (int) env('RAG_IMPORTANCE_MAX_REASON_COUNT', 5),
        'max_reason_length' => (int) env('RAG_IMPORTANCE_MAX_REASON_LENGTH', 280),
        'stale_after_minutes' => (int) env('RAG_IMPORTANCE_STALE_AFTER_MINUTES', 15),
        'queue' => env('RAG_IMPORTANCE_QUEUE', 'classification'),
        // A dedicated connection, not just a queue name on the default
        // connection: its `retry_after` (config/queue.php) is deliberately
        // sized above this job's own `$timeout`
        // (see ClassifyKnowledgeEntryJob::classificationRetryAfterSeconds()),
        // which would silently regress if classification shared the default
        // `database` connection's 90s retry_after.
        'queue_connection' => env('RAG_IMPORTANCE_QUEUE_CONNECTION', 'classification'),
        // The reviewed calibration corpus `rag:importance-report` re-runs through
        // the deterministic rules for the must-keep gate. Lives under `resources/`
        // (not `tests/`) because `.dockerignore` excludes `tests/` from the
        // production image and this command has to run there. Overridable so
        // tests can point the gate at a fixture without touching the shipped
        // corpus.
        'must_keep_corpus_path' => env('RAG_IMPORTANCE_MUST_KEEP_CORPUS_PATH', resource_path('importance/must-keep.json')),
    ],
];
