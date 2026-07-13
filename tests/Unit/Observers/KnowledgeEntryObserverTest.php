<?php

// tests/Unit/Observers/KnowledgeEntryObserverTest.php

use App\Enums\KnowledgeStatus;
use App\Jobs\IndexEntryJob;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Services\Indexing\EntryIndexer;
use App\Services\Knowledge\KnowledgeWriter;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;

beforeEach(function () {
    Project::create(['id' => 'p1', 'name' => 'p1', 'root_path' => '/tmp/p1']);
});

it('indexes pending entries on create', function () {
    Queue::fake();

    $entry = KnowledgeEntry::create([
        'project_id' => 'p1', 'title' => 't', 'content' => 'c',
        'category' => 'insight', 'source' => 'condense', 'status' => 'pending',
    ]);

    Queue::assertPushed(IndexEntryJob::class, fn ($job) => $job->entryId === (int) $entry->id);
});

it('still indexes approved entries on create', function () {
    Queue::fake();

    KnowledgeEntry::create([
        'project_id' => 'p1', 'title' => 't', 'content' => 'c',
        'category' => 'insight', 'source' => 'manual', 'status' => 'approved',
    ]);

    Queue::assertPushed(IndexEntryJob::class);
});

it('does not index an entry that is still being classified', function () {
    Queue::fake();

    KnowledgeEntry::create([
        'project_id' => 'p1', 'title' => 't', 'content' => 'c',
        'category' => 'insight', 'source' => 'condense', 'status' => KnowledgeStatus::Classifying->value,
    ]);

    Queue::assertNotPushed(IndexEntryJob::class);
});

it('indexes exactly once when classification releases the entry to pending', function () {
    Queue::fake();

    $entry = KnowledgeEntry::create([
        'project_id' => 'p1', 'title' => 't', 'content' => 'c',
        'category' => 'insight', 'source' => 'condense', 'status' => KnowledgeStatus::Classifying->value,
    ]);

    Queue::fake();

    // The classification job writes the verdict snapshot and the status in one
    // save, exactly as ClassifyKnowledgeEntryJob does.
    $entry->update([
        'status' => KnowledgeStatus::Pending->value,
        'metadata' => ['importance' => ['verdict' => 'important']],
    ]);

    Queue::assertPushed(
        IndexEntryJob::class,
        fn (IndexEntryJob $job) => $job->entryId === (int) $entry->id
            && $job->afterCommit === true,
    );
    Queue::assertPushed(IndexEntryJob::class, 1);
});

it('does not index an entry rejected straight out of classification', function () {
    Queue::fake();

    $entry = KnowledgeEntry::create([
        'project_id' => 'p1', 'title' => 't', 'content' => 'c',
        'category' => 'insight', 'source' => 'condense', 'status' => KnowledgeStatus::Classifying->value,
    ]);

    Queue::fake();

    $entry->update(['status' => KnowledgeStatus::Rejected->value]);

    Queue::assertNotPushed(IndexEntryJob::class);
});

it('does not index a classifying entry whose content is edited mid-flight', function () {
    Queue::fake();

    $entry = KnowledgeEntry::create([
        'project_id' => 'p1', 'title' => 't', 'content' => 'c',
        'category' => 'insight', 'source' => 'condense', 'status' => KnowledgeStatus::Classifying->value,
    ]);

    Queue::fake();

    $entry->update(['content' => 'edited while classifying']);

    Queue::assertNotPushed(IndexEntryJob::class);
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
        fn (IndexEntryJob $job) => $job->entryId === (int) $entry->id
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
        fn (IndexEntryJob $job) => $job->entryId === (int) $entry->id
            && $job->afterCommit === true,
    );
});

it('reindexes when embedded content changes', function () {
    Queue::fake();

    $entry = KnowledgeEntry::create([
        'project_id' => 'p1', 'title' => 't', 'content' => 'c',
        'category' => 'insight', 'source' => 'manual', 'status' => 'pending',
    ]);

    Queue::fake();

    $entry->update(['content' => 'updated content']);

    Queue::assertPushed(
        IndexEntryJob::class,
        fn (IndexEntryJob $job) => $job->entryId === (int) $entry->id
            && $job->afterCommit === true,
    );
});

it('does not reindex a metadata-only update', function () {
    Queue::fake();

    $entry = KnowledgeEntry::create([
        'project_id' => 'p1', 'title' => 't', 'content' => 'c',
        'category' => 'insight', 'source' => 'manual', 'status' => 'pending',
    ]);

    Queue::fake();

    $entry->update(['metadata' => ['key' => 'value']]);

    Queue::assertNotPushed(IndexEntryJob::class);
});

it('does not reindex approval when the pending entry already has chunks', function () {
    Queue::fake();

    $entry = KnowledgeEntry::create([
        'project_id' => 'p1', 'title' => 't', 'content' => 'c',
        'category' => 'insight', 'source' => 'manual', 'status' => 'pending',
    ]);

    DB::table('chunk_embeddings')->insert([
        'entry_id' => $entry->id,
        'project_id' => 'p1',
        'chunk_index' => 0,
        'content' => 'chunk text',
        'embedding' => '['.implode(',', array_fill(0, 768, '0.1')).']',
    ]);

    Queue::fake();

    $entry->update(['status' => 'approved']);

    Queue::assertNotPushed(IndexEntryJob::class);
});

it('does not queue duplicate indexing when a pending entry is approved before its first job runs', function () {
    Queue::fake();

    $entry = KnowledgeEntry::create([
        'project_id' => 'p1', 'title' => 't', 'content' => 'c',
        'category' => 'insight', 'source' => 'manual', 'status' => 'pending',
    ]);

    $entry->update(['status' => 'approved']);

    Queue::assertPushed(IndexEntryJob::class, 1);
});

it('does not reindex approval after successful zero-chunk indexing', function () {
    Queue::fake();
    Embeddings::fake()->preventStrayEmbeddings();

    $entry = KnowledgeEntry::create([
        'project_id' => 'p1', 'title' => 't', 'content' => '# Heading only',
        'category' => 'insight', 'source' => 'manual', 'status' => 'pending',
    ]);
    $job = new IndexEntryJob((int) $entry->id);

    $job->handle(app(EntryIndexer::class));
    (new UniqueLock(app(CacheRepository::class)))->release($job);

    expect($entry->chunks()->exists())->toBeFalse();
    Queue::fake();

    $entry->update(['status' => 'approved']);

    Queue::assertNotPushed(IndexEntryJob::class);
});

it('deletes chunks when an entry is rejected', function () {
    Queue::fake();

    $entry = KnowledgeEntry::create([
        'project_id' => 'p1', 'title' => 't', 'content' => 'c',
        'category' => 'insight', 'source' => 'manual', 'status' => 'pending',
    ]);

    DB::table('chunk_embeddings')->insert([
        'entry_id' => $entry->id,
        'project_id' => 'p1',
        'chunk_index' => 0,
        'content' => 'chunk text',
        'embedding' => '['.implode(',', array_fill(0, 768, '0.1')).']',
    ]);

    Queue::fake();

    $entry->update(['status' => 'rejected']);

    Queue::assertNotPushed(IndexEntryJob::class);
    expect(DB::table('chunk_embeddings')->where('entry_id', $entry->id)->exists())->toBeFalse();
});

it('deletes stale chunks when an already rejected entry is updated', function () {
    Queue::fake();

    $entry = KnowledgeEntry::create([
        'project_id' => 'p1', 'title' => 't', 'content' => 'c',
        'category' => 'insight', 'source' => 'manual', 'status' => 'rejected',
    ]);

    DB::table('chunk_embeddings')->insert([
        'entry_id' => $entry->id,
        'project_id' => 'p1',
        'chunk_index' => 0,
        'content' => 'stale chunk text',
        'embedding' => '['.implode(',', array_fill(0, 768, '0.1')).']',
    ]);

    Queue::fake();

    $entry->update(['metadata' => ['key' => 'value']]);

    Queue::assertNotPushed(IndexEntryJob::class);
    expect(DB::table('chunk_embeddings')->where('entry_id', $entry->id)->exists())->toBeFalse();
});
