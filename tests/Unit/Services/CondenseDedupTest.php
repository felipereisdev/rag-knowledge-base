<?php

use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Services\Condense\CondenseDedup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

function insertChunk(string $entryId, string $projectId, array $vec): void
{
    // chunk_embeddings columns: entry_id(bigint), project_id, chunk_index,
    // content, embedding(vector 768), created_at (defaults to now()).
    DB::table('chunk_embeddings')->insert([
        'entry_id' => $entryId,
        'project_id' => $projectId,
        'chunk_index' => 0,
        'content' => 'x',
        'embedding' => '['.implode(',', $vec).']',
    ]);
}

function dedupWith(array $queryVec): CondenseDedup
{
    return new class($queryVec) extends CondenseDedup
    {
        public function __construct(private array $qv) {}

        protected function embed(string $text): array
        {
            return $this->qv;
        }
    };
}

beforeEach(function () {
    // Prevent the observer from synchronously indexing created entries (which
    // would insert a chunk_index=0 row and collide with insertChunk, and hit
    // the real embedder). This test inserts the chunks it needs by hand.
    Queue::fake();
    Project::create(['id' => 'p1', 'name' => 'p1', 'root_path' => '/tmp/p1']);
});

it('flags a near-identical vector as duplicate', function () {
    $vec = array_fill(0, 768, 0.0);
    $vec[0] = 1.0;
    $entry = KnowledgeEntry::create(['project_id' => 'p1', 'title' => 't', 'content' => 'c', 'category' => 'insight', 'source' => 'manual', 'status' => 'pending']);
    insertChunk((string) $entry->id, 'p1', $vec);

    expect(dedupWith($vec)->isDuplicate('p1', 'title', 'content', 0.85))->toBeTrue();
});

it('does not flag an orthogonal vector', function () {
    $stored = array_fill(0, 768, 0.0);
    $stored[0] = 1.0;
    $query = array_fill(0, 768, 0.0);
    $query[1] = 1.0;
    $entry = KnowledgeEntry::create(['project_id' => 'p1', 'title' => 't', 'content' => 'c', 'category' => 'insight', 'source' => 'manual', 'status' => 'pending']);
    insertChunk((string) $entry->id, 'p1', $stored);

    expect(dedupWith($query)->isDuplicate('p1', 'title', 'content', 0.85))->toBeFalse();
});

it('returns false when the project has no chunks', function () {
    $vec = array_fill(0, 768, 0.0);
    $vec[0] = 1.0;
    expect(dedupWith($vec)->isDuplicate('p1', 'title', 'content', 0.85))->toBeFalse();
});

it('uses the configured embedding provider', function () {
    config([
        'rag.embeddings.provider' => 'custom-embedder',
        'ai.providers.custom-embedder' => config('ai.providers.local-embedder'),
    ]);

    $vec = array_fill(0, 768, 0.0);
    $vec[0] = 1.0;
    $entry = KnowledgeEntry::create(['project_id' => 'p1', 'title' => 't', 'content' => 'c', 'category' => 'insight', 'source' => 'manual', 'status' => 'pending']);
    insertChunk((string) $entry->id, 'p1', $vec);
    Embeddings::fake([[$vec]]);

    expect((new CondenseDedup)->isDuplicate('p1', 'title', 'content', 0.85))->toBeTrue();
    Embeddings::assertGenerated(
        fn (EmbeddingsPrompt $prompt): bool => $prompt->provider->name() === 'custom-embedder',
    );
});
