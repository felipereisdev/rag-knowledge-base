<?php

// tests/Unit/Observers/KnowledgeEntryObserverTest.php

use App\Jobs\IndexEntryJob;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Services\Knowledge\KnowledgeWriter;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Project::create(['id' => 'p1', 'name' => 'p1', 'root_path' => '/tmp/p1']);
});

it('indexes pending entries on create', function () {
    Queue::fake();

    $entry = KnowledgeEntry::create([
        'project_id' => 'p1', 'title' => 't', 'content' => 'c',
        'category' => 'insight', 'source' => 'condense', 'status' => 'pending',
    ]);

    Queue::assertPushed(IndexEntryJob::class, fn ($job) => $job->entryId === (string) $entry->id);
});

it('still indexes approved entries on create', function () {
    Queue::fake();

    KnowledgeEntry::create([
        'project_id' => 'p1', 'title' => 't', 'content' => 'c',
        'category' => 'insight', 'source' => 'manual', 'status' => 'approved',
    ]);

    Queue::assertPushed(IndexEntryJob::class);
});

it('queues writer-created entries for indexing after commit', function () {
    Queue::fake();

    $entry = app(KnowledgeWriter::class)->store(
        projectId: 'p1',
        title: 't',
        content: 'c',
        category: 'insight',
        source: 'import',
    );

    Queue::assertPushed(
        IndexEntryJob::class,
        fn (IndexEntryJob $job) => $job->entryId === (string) $entry->id
            && $job->afterCommit === true,
    );
});

it('queues updated entries for indexing after commit', function () {
    Queue::fake();

    $entry = KnowledgeEntry::create([
        'project_id' => 'p1', 'title' => 't', 'content' => 'c',
        'category' => 'insight', 'source' => 'manual', 'status' => 'rejected',
    ]);

    $entry->update(['status' => 'pending']);

    Queue::assertPushed(
        IndexEntryJob::class,
        fn (IndexEntryJob $job) => $job->entryId === (string) $entry->id
            && $job->afterCommit === true,
    );
});
