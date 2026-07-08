<?php

use App\Mcp\Servers\RagServer;
use App\Mcp\Tools\RagSearchTool;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;

beforeEach(function () {
    Queue::fake();
    // Fake embeddings: the local-embedder provider is configured for 768
    // dimensions. The query embedding must match the stored chunk embedding
    // dimension-for-dimension so pgvector cosine distance returns a perfect
    // similarity (1.0). Identical vectors => cosine similarity = 1.0.
    $vector = array_fill(0, 768, 0.1);
    Embeddings::fake([
        [$vector],
    ]);

    $this->project = Project::create([
        'id' => 'test-project',
        'name' => 'Test Project',
        'root_path' => '/tmp/test-project',
    ]);

    $entry = KnowledgeEntry::create([
        'project_id' => $this->project->id,
        'title' => 'Auth uses JWT',
        'content' => 'The auth system uses JWT with refresh tokens.',
        'category' => 'architecture',
        'status' => 'approved',
        'source' => 'manual',
    ]);

    // Insert chunk manually (observer dispatches job, but Queue::fake prevents it).
    // The chunk embedding must be the same 768-dim vector the fake will return
    // for the query so cosine similarity lands above the default min_score.
    // The id column is bigIncrements, so it is omitted (auto-increment).
    DB::table('chunk_embeddings')->insert([
        'entry_id' => $entry->id,
        'project_id' => $this->project->id,
        'chunk_index' => 0,
        'content' => 'The auth system uses JWT with refresh tokens.',
        'embedding' => '['.implode(',', $vector).']',
    ]);
});

it('returns search results for a matching query', function () {
    $response = RagServer::tool(RagSearchTool::class, [
        'project_id' => 'test-project',
        'query' => 'authentication JWT',
    ]);

    $response->assertOk();
    $response->assertSee('Auth uses JWT');
    $response->assertSee('architecture');
});

it('returns no-results message when project has no indexed entries', function () {
    Project::create([
        'id' => 'empty-project',
        'name' => 'Empty',
        'root_path' => '/tmp/empty',
    ]);

    $response = RagServer::tool(RagSearchTool::class, [
        'project_id' => 'empty-project',
        'query' => 'anything',
    ]);

    $response->assertOk();
    $response->assertSee('No indexed knowledge');
});

it('returns not-found for unknown project', function () {
    $response = RagServer::tool(RagSearchTool::class, [
        'project_id' => 'nonexistent',
        'query' => 'test',
    ]);

    $response->assertOk();
    $response->assertSee("Project 'nonexistent' not found");
});
