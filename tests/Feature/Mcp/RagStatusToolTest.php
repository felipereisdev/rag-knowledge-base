<?php

use App\Mcp\Servers\RagServer;
use App\Mcp\Tools\RagStatusTool;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use Laravel\Ai\Embeddings;

beforeEach(function () {
    // Fake embeddings so the KnowledgeEntryObserver's IndexEntryJob
    // does not hit the embedder sidecar when entries are created.
    // The local-embedder provider is configured for 768 dimensions.
    $fakeVector = array_fill(0, 768, 0.1);
    Embeddings::fake([
        [$fakeVector],
    ]);

    $this->project = Project::create([
        'id' => 'test-project',
        'name' => 'Test Project',
        'root_path' => '/tmp/test-project',
        'language' => 'pt-BR',
    ]);
});

it('returns status for an existing project', function () {
    KnowledgeEntry::create([
        'project_id' => $this->project->id,
        'title' => 'Rule 1',
        'content' => 'Content here',
        'category' => 'business-rule',
        'status' => 'approved',
    ]);
    KnowledgeEntry::create([
        'project_id' => $this->project->id,
        'title' => 'Pending rule',
        'content' => 'Pending content',
        'category' => 'insight',
        'status' => 'pending',
    ]);

    $response = RagServer::tool(RagStatusTool::class, [
        'project_id' => 'test-project',
    ]);

    $response->assertOk();
    $response->assertSee('Project: Test Project (test-project)');
    $response->assertSee('Language: pt-BR');
    $response->assertSee('Total: 2');
    $response->assertSee('Approved: 1');
    $response->assertSee('Pending: 1');
});

it('returns a not-found message for an unknown project', function () {
    $response = RagServer::tool(RagStatusTool::class, [
        'project_id' => 'nonexistent',
    ]);

    $response->assertOk();
    $response->assertSee("Project 'nonexistent' not found");
});

it('auto-creates a project from cwd when project_id is omitted', function () {
    $response = RagServer::tool(RagStatusTool::class, [
        'cwd' => '/tmp/auto-created-project',
    ]);

    $response->assertOk();
    $response->assertSee('auto-created-project');

    $this->assertDatabaseHas('projects', [
        'id' => 'auto-created-project',
    ]);
});
