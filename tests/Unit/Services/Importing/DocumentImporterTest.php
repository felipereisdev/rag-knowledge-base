<?php

use App\Enums\ImportanceClassifierMode;
use App\Enums\KnowledgeSource;
use App\Enums\KnowledgeStatus;
use App\Jobs\ClassifyKnowledgeEntryJob;
use App\Models\ImportanceClassifierSetting;
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

    $this->importer = app(DocumentImporter::class);
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

it('keeps sibling H2 sections under their parent H1', function () {
    $path = tempnam(sys_get_temp_dir(), 'rag_test_');
    $markdownPath = "{$path}.md";
    rename($path, $markdownPath);
    $path = $markdownPath;
    file_put_contents($path, "# Parent\n\nIntro.\n\n## First\n\nOne.\n\n## Second\n\nTwo.");

    try {
        $entryIds = $this->importer->import($this->project->id, $path, 'documentation', ['docs']);

        $titles = KnowledgeEntry::whereIn('id', $entryIds)->pluck('title')->all();

        expect($titles)->toBe(['Parent', 'Parent / First', 'Parent / Second']);
    } finally {
        @unlink($path);
    }
});

it('imports through the shared writer flow', function () {
    $path = tempnam(sys_get_temp_dir(), 'rag_test_');
    $textPath = "{$path}.txt";
    rename($path, $textPath);
    $path = $textPath;
    file_put_contents($path, 'Shared writer import.');

    try {
        $entryIds = $this->importer->import($this->project->id, $path, 'documentation', ['docs']);
        $entry = KnowledgeEntry::with('tags')->findOrFail($entryIds[0]);

        expect($entry->source)->toBe('import')
            ->and($entry->status)->toBe('pending')
            ->and($entry->category)->toBe('documentation')
            ->and($entry->tags->pluck('name')->all())->toBe(['docs']);
    } finally {
        @unlink($path);
    }
});

it('keeps imported entries pending and never classifies them', function (ImportanceClassifierMode $mode) {
    // Regression: a bulk import is a human's deliberate corpus, not captured
    // insight. Whatever the classifier mode, imported entries go straight to
    // approval and no classification job is ever dispatched for them.
    ImportanceClassifierSetting::query()->findOrFail(1)->update(['mode' => $mode->value]);

    $path = tempnam(sys_get_temp_dir(), 'rag_test_').'.md';
    file_put_contents($path, "# A\n\nContent A.\n\n# B\n\nContent B.");

    try {
        $entryIds = $this->importer->import($this->project->id, $path, 'documentation', ['docs']);

        expect($entryIds)->toHaveCount(2);
        foreach ($entryIds as $id) {
            $entry = KnowledgeEntry::findOrFail($id);
            expect($entry->source)->toBe(KnowledgeSource::Import->value)
                ->and($entry->status)->toBe(KnowledgeStatus::Pending->value);
        }

        Queue::assertNotPushed(ClassifyKnowledgeEntryJob::class);
    } finally {
        @unlink($path);
    }
})->with([
    'shadow' => ImportanceClassifierMode::Shadow,
    'enforce' => ImportanceClassifierMode::Enforce,
    'off' => ImportanceClassifierMode::Off,
]);

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
