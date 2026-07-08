<?php

use App\Models\KnowledgeEntry;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;

beforeEach(function () {
    Queue::fake();

    $vector = array_fill(0, 768, 0.1);
    Embeddings::fake([[$vector]]);

    $this->project = Project::create([
        'id' => 'test-project',
        'name' => 'Test',
        'root_path' => '/tmp/test',
    ]);

    $entry = KnowledgeEntry::create([
        'project_id' => $this->project->id,
        'title' => 'Auth uses JWT',
        'content' => 'The auth system uses JWT with refresh tokens.',
        'category' => 'architecture',
        'status' => 'approved',
        'source' => 'manual',
    ]);

    DB::table('chunk_embeddings')->insert([
        'entry_id' => $entry->id,
        'project_id' => $this->project->id,
        'chunk_index' => 0,
        'content' => 'The auth system uses JWT with refresh tokens.',
        'embedding' => '['.implode(',', $vector).']',
    ]);
});

it('returns search results for a matching query', function () {
    $this->artisan('rag:search', [
        'query' => 'authentication',
        '--project' => 'test-project',
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Auth uses JWT');
});

it('returns no-results message when nothing matches', function () {
    // The fake embedder returns the same vector regardless of query, so
    // the vector path would normally return a perfect (1.0) similarity
    // hit. Setting min-score above the maximum possible cosine similarity
    // (1.0) filters out the vector hit too, leaving no results.
    $this->artisan('rag:search', [
        'query' => 'completely unrelated topic',
        '--project' => 'test-project',
        '--min-score' => '1.5',
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('No results');
});

it('warns for unknown project', function () {
    $this->artisan('rag:search', [
        'query' => 'test',
        '--project' => 'nonexistent',
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('not found');
});
