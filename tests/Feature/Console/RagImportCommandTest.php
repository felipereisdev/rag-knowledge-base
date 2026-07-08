<?php

use App\Models\KnowledgeEntry;
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

it('imports a markdown file', function () {
    $path = tempnam(sys_get_temp_dir(), 'rag_test_').'.md';
    file_put_contents($path, "# Section A\n\nContent A.\n\n# Section B\n\nContent B.");

    $this->artisan('rag:import', [
        'path' => $path,
        '--project' => 'test-project',
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Imported 2 entries');

    expect(KnowledgeEntry::count())->toBe(2);

    unlink($path);
});

it('fails for unsupported extension', function () {
    $path = tempnam(sys_get_temp_dir(), 'rag_test_').'.pdf';
    file_put_contents($path, 'content');

    $this->artisan('rag:import', [
        'path' => $path,
        '--project' => 'test-project',
    ])
        ->assertFailed()
        ->expectsOutputToContain('Unsupported file type');

    unlink($path);
});

it('fails for nonexistent file', function () {
    $this->artisan('rag:import', [
        'path' => '/nonexistent.md',
        '--project' => 'test-project',
    ])
        ->assertFailed()
        ->expectsOutputToContain('File not found');
});
