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

it('respects the limit parameter', function () {
    // Add a second approved entry with a chunk. The fake embedder returns
    // the same 768-dim vector for every query, so vector search will rank
    // both entries at similarity 1.0 — without the limit, both would be
    // returned.
    $second = KnowledgeEntry::create([
        'project_id' => $this->project->id,
        'title' => 'Database uses Postgres',
        'content' => 'The database layer uses Postgres with pgvector extension.',
        'category' => 'architecture',
        'status' => 'approved',
        'source' => 'manual',
    ]);

    $vector = array_fill(0, 768, 0.1);
    DB::table('chunk_embeddings')->insert([
        'entry_id' => $second->id,
        'project_id' => $this->project->id,
        'chunk_index' => 0,
        'content' => 'The database layer uses Postgres with pgvector extension.',
        'embedding' => '['.implode(',', $vector).']',
    ]);

    $response = RagServer::tool(RagSearchTool::class, [
        'project_id' => 'test-project',
        'query' => 'architecture',
        'limit' => 1,
    ]);

    $response->assertOk();
    // Only one result: the second positional marker "[2]" must not appear.
    $response->assertDontSee('[2]');
});

it('respects the min_score parameter', function () {
    // Query shares no keywords with the stored content ("The auth system
    // uses JWT with refresh tokens."), so the FTS path returns nothing.
    // The fake embedder returns the same vector regardless of query, so
    // the vector path would normally return a perfect (1.0) similarity
    // hit. Setting min_score above the maximum possible cosine similarity
    // (1.0) filters out the vector hit too, leaving no results.
    $response = RagServer::tool(RagSearchTool::class, [
        'project_id' => 'test-project',
        'query' => 'elephant giraffe',
        'min_score' => 1.5,
    ]);

    $response->assertOk();
    $response->assertSee('No results');
});

it('excludes non-approved entries from results', function () {
    // Pending entry whose chunk shares the same embedding as the query
    // vector. Without status filtering on the vector path, this entry
    // would surface in results despite being unapproved.
    $pending = KnowledgeEntry::create([
        'project_id' => $this->project->id,
        'title' => 'Secret pending draft',
        'content' => 'Draft entry awaiting approval should not surface in search.',
        'category' => 'insight',
        'status' => 'pending',
        'source' => 'manual',
    ]);

    $vector = array_fill(0, 768, 0.1);
    DB::table('chunk_embeddings')->insert([
        'entry_id' => $pending->id,
        'project_id' => $this->project->id,
        'chunk_index' => 0,
        'content' => 'Draft entry awaiting approval should not surface in search.',
        'embedding' => '['.implode(',', $vector).']',
    ]);

    $response = RagServer::tool(RagSearchTool::class, [
        'project_id' => 'test-project',
        'query' => 'draft approval',
    ]);

    $response->assertOk();
    $response->assertDontSee('Secret pending draft');
});
