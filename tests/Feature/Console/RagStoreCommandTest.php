<?php

use App\Models\KnowledgeEntry;
use App\Models\Project;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;

beforeEach(function () {
    Queue::fake();

    $fakeVector = array_fill(0, 768, 0.1);
    Embeddings::fake([[$fakeVector]]);
});

it('stores an entry from options', function () {
    Project::create([
        'id' => 'test-project',
        'name' => 'Test',
        'root_path' => '/tmp/test',
    ]);

    $this->artisan('rag:store', [
        'title' => 'Test Rule',
        '--project' => 'test-project',
        '--content' => 'This is the content.',
        '--category' => 'business-rule',
        '--tags' => 'tag1,tag2',
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Knowledge entry stored');

    $entry = KnowledgeEntry::where('title', 'Test Rule')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->category)->toBe('business-rule')
        ->and($entry->tags->pluck('name')->all())->toBe(['tag1', 'tag2']);
});

it('reads content from --content-file when used', function () {
    Project::create([
        'id' => 'test-project',
        'name' => 'Test',
        'root_path' => '/tmp/test',
    ]);

    $path = tempnam(sys_get_temp_dir(), 'rag_test_');
    file_put_contents($path, 'Content from file.');

    $this->artisan('rag:store', [
        'title' => 'Stdin Rule',
        '--project' => 'test-project',
        '--content-file' => $path,
    ])
        ->assertSuccessful();

    $entry = KnowledgeEntry::where('title', 'Stdin Rule')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->content)->toBe('Content from file.');

    unlink($path);
});

it('fails when neither content nor content-file is provided', function () {
    $this->artisan('rag:store', [
        'title' => 'No Content',
        '--project' => 'test-project',
    ])
        ->assertFailed()
        ->expectsOutputToContain('Provide either --content or --content-file');
});

it('auto-creates project from cwd when --project omitted', function () {
    $this->artisan('rag:store', [
        'title' => 'Auto Project',
        '--content' => 'Content.',
    ])
        ->assertSuccessful();

    $entry = KnowledgeEntry::where('title', 'Auto Project')->first();
    expect($entry)->not->toBeNull();
});
