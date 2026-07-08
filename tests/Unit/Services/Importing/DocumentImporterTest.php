<?php

use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Services\Importing\DocumentImporter;
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

    $this->importer = new DocumentImporter;
});

it('splits markdown by H1 and H2 headers', function () {
    $path = tempnam(sys_get_temp_dir(), 'rag_test_').'.md';
    file_put_contents($path, "# Section One\n\nContent of section one.\n\n## Subsection\n\nMore content.\n\n# Section Two\n\nSecond section content.");

    $entryIds = $this->importer->import($this->project->id, $path, 'business-rule', ['imported']);

    expect($entryIds)->toHaveCount(3);
    $this->assertDatabaseCount('knowledge_entries', 3);

    $titles = KnowledgeEntry::whereIn('id', $entryIds)->pluck('title')->all();
    expect($titles)->toContain('Section One')
        ->and($titles)->toContain('Section One / Subsection')
        ->and($titles)->toContain('Section Two');

    unlink($path);
});

it('imports a .txt file as a single entry', function () {
    $path = tempnam(sys_get_temp_dir(), 'rag_test_').'.txt';
    file_put_contents($path, "Just some plain text content.\n\nWith two paragraphs.");

    $entryIds = $this->importer->import($this->project->id, $path, 'insight', null);

    expect($entryIds)->toHaveCount(1);
    expect(KnowledgeEntry::first()->title)->toBe(basename($path));
    unlink($path);
});

it('throws on nonexistent file', function () {
    $this->expectException(InvalidArgumentException::class);
    $this->importer->import($this->project->id, '/nonexistent/path.md', 'business-rule', []);
});

it('throws on unsupported extension', function () {
    $path = tempnam(sys_get_temp_dir(), 'rag_test_').'.pdf';
    file_put_contents($path, 'content');

    $this->expectException(InvalidArgumentException::class);
    $this->importer->import($this->project->id, $path, 'business-rule', []);

    unlink($path);
});

it('attaches tags to all imported entries', function () {
    $path = tempnam(sys_get_temp_dir(), 'rag_test_').'.md';
    file_put_contents($path, "# A\n\nContent A.\n\n# B\n\nContent B.");

    $entryIds = $this->importer->import($this->project->id, $path, 'insight', ['docs', 'imported']);

    foreach ($entryIds as $id) {
        $entry = KnowledgeEntry::find($id);
        expect($entry->tags->pluck('name')->all())->toBe(['docs', 'imported']);
    }

    unlink($path);
});
