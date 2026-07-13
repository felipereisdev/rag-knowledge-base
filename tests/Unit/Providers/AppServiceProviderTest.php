<?php

use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Providers\AppServiceProvider;
use Database\Seeders\EmbeddingModelStateSeeder;
use Illuminate\Support\Facades\DB;

it('rejects embedding dimensions incompatible with the persisted vector column', function () {
    $column = DB::selectOne(
        "SELECT format_type(atttypid, atttypmod) AS type
         FROM pg_attribute
         WHERE attrelid = 'chunk_embeddings'::regclass
         AND attname = 'embedding'",
    );
    expect($column->type)->toBe('vector(768)');

    config(['rag.embeddings.dimension' => 384]);

    expect(fn () => (new AppServiceProvider(app()))->register())
        ->toThrow(
            InvalidArgumentException::class,
            'RAG_EMBEDDING_DIM must be 768 because chunk_embeddings.embedding is vector(768); variable embedding dimensions are not supported.',
        );
});

it('invalidates stored embeddings when the configured provider changes', function () {
    config([
        'rag.embeddings.provider' => 'custom-embedder',
        'rag.embeddings.model' => 'paraphrase-multilingual-mpnet-base-v2',
        'rag.embeddings.dimension' => 768,
    ]);

    DB::table('embedding_model_state')->updateOrInsert(
        ['id' => 1],
        [
            'model_name' => 'paraphrase-multilingual-mpnet-base-v2',
            'model_dim' => 768,
            'embedded_at' => now(),
        ],
    );

    $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
    $entry = KnowledgeEntry::create([
        'project_id' => $project->id,
        'title' => 'Existing embedding',
        'content' => 'Existing embedding content.',
    ]);
    DB::table('chunk_embeddings')->insert([
        'entry_id' => $entry->id,
        'project_id' => $project->id,
        'chunk_index' => 0,
        'content' => $entry->content,
        'embedding' => '['.implode(',', array_fill(0, 768, 0.1)).']',
    ]);

    (new AppServiceProvider(app()))->boot();

    expect(DB::table('chunk_embeddings')->count())->toBe(0)
        ->and(DB::table('embedding_model_state')->where('id', 1)->value('provider_name'))->toBe('custom-embedder');
});

it('configures the selected AI provider from centralized embedding settings', function () {
    config([
        'rag.embeddings.provider' => 'custom-embedder',
        'rag.embeddings.model' => 'custom-model',
        'rag.embeddings.dimension' => 768,
        'ai.providers.custom-embedder' => config('ai.providers.local-embedder'),
    ]);

    (new AppServiceProvider(app()))->register();

    expect(config('ai.providers.custom-embedder.models.embeddings.default'))->toBe('custom-model')
        ->and(config('ai.providers.custom-embedder.models.embeddings.dimensions'))->toBe(768);
});

it('seeds embedding state from centralized embedding settings', function () {
    config([
        'rag.embeddings.provider' => 'custom-embedder',
        'rag.embeddings.model' => 'custom-model',
        'rag.embeddings.dimension' => 768,
    ]);

    (new EmbeddingModelStateSeeder)->run();

    $state = DB::table('embedding_model_state')->where('id', 1)->first();

    expect((array) $state)
        ->toHaveKey('provider_name', 'custom-embedder')
        ->toHaveKey('model_name', 'custom-model')
        ->toHaveKey('model_dim', 768);
});

it('does not expose obsolete RAG settings under app config', function () {
    expect(config('app.rag_embedding_model'))->toBeNull()
        ->and(config('app.rag_embedding_dim'))->toBeNull()
        ->and(config('app.rag_search_min_score'))->toBeNull()
        ->and(config('app.rag_search_limit'))->toBeNull()
        ->and(config('app.rag_search_rrf_k'))->toBeNull()
        ->and(config('app.rag_search_graph_expand'))->toBeNull()
        ->and(config('app.rag_search_graph_weight'))->toBeNull()
        ->and(config('app.rag_search_vector_top_k'))->toBeNull()
        ->and(config('app.rag_search_fts_top_k'))->toBeNull();
});
