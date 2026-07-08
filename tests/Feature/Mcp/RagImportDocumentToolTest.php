<?php

use App\Mcp\Servers\RagServer;
use App\Mcp\Tools\RagImportDocumentTool;
use App\Models\Project;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;

beforeEach(function () {
    Queue::fake();

    $fakeVector = array_fill(0, 768, 0.1);
    Embeddings::fake([[$fakeVector]]);

    $this->project = Project::create([
        'id' => 'test-project',
        'name' => 'Test',
        'root_path' => '/tmp/test',
    ]);
});

it('imports a markdown file and reports count', function () {
    $path = tempnam(sys_get_temp_dir(), 'rag_test_').'.md';
    file_put_contents($path, "# Section A\n\nContent A.\n\n# Section B\n\nContent B.");

    $response = RagServer::tool(RagImportDocumentTool::class, [
        'project_id' => 'test-project',
        'file_path' => $path,
        'category' => 'business-rule',
    ]);

    $response->assertOk();

    $response->assertSee('Imported 2 entries');
    $response->assertSee($path);

    unlink($path);
});

it('returns error for nonexistent file', function () {
    $response = RagServer::tool(RagImportDocumentTool::class, [
        'project_id' => 'test-project',
        'file_path' => '/nonexistent.md',
    ]);

    $response->assertOk();
    $response->assertSee('File not found');
});

it('returns error for unsupported extension', function () {
    $path = tempnam(sys_get_temp_dir(), 'rag_test_').'.pdf';
    file_put_contents($path, 'content');

    $response = RagServer::tool(RagImportDocumentTool::class, [
        'project_id' => 'test-project',
        'file_path' => $path,
    ]);

    $response->assertOk();
    $response->assertSee('Unsupported file type');

    unlink($path);
});

it('auto-creates project from cwd when project_id omitted', function () {
    $path = tempnam(sys_get_temp_dir(), 'rag_test_').'.md';
    file_put_contents($path, "# Test\n\nContent.");

    $response = RagServer::tool(RagImportDocumentTool::class, [
        'file_path' => $path,
        'cwd' => '/tmp/new-import-project',
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('projects', ['id' => 'new-import-project']);

    unlink($path);
});
