<?php

use App\Enums\ImportanceClassifierMode;
use App\Enums\KnowledgeSource;
use App\Enums\KnowledgeStatus;
use App\Jobs\ClassifyKnowledgeEntryJob;
use App\Jobs\IndexEntryJob;
use App\Models\Entity;
use App\Models\ImportanceClassifierSetting;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Models\Relation;
use App\Services\Knowledge\KnowledgeWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Project::create(['id' => 'p1', 'name' => 'p1', 'root_path' => '/tmp/p1']);
});

afterEach(function () {
    // The probe connection commits for real (see useSeparateQueueConnection()),
    // so RefreshDatabase's rollback does not clean up after it.
    if (array_key_exists('queue_probe', (array) config('database.connections'))) {
        DB::connection('queue_probe')->table('jobs')->delete();
    }
});

function ingestionMode(ImportanceClassifierMode $mode): void
{
    ImportanceClassifierSetting::query()->findOrFail(1)->update(['mode' => $mode->value]);
}

/**
 * Fakes *only* the indexing job, so a `pending` write cannot reach the real
 * embedder through the `sync` connection, while the classification job still
 * travels the real queue driver — the only way `afterCommit()` deferral (which
 * QueueFake does not model at all) can be observed.
 */
function fakeIndexingOnly(): void
{
    Queue::fake([IndexEntryJob::class]);
}

/**
 * Pushes classification onto a database queue that lives on its own PDO
 * connection — as a Redis queue, or a worker's connection, does in production.
 *
 * This is what makes the atomicity tests bite: on the *default* connection an
 * eager push would land inside the writer's own transaction and be rolled back
 * with it, so `afterCommit()` and a bare `dispatch()` would look identical. On a
 * separate connection an eager push commits immediately and outlives a rollback,
 * leaving a job that points at an entry which never existed.
 */
function useSeparateQueueConnection(): void
{
    config([
        'database.connections.queue_probe' => config('database.connections.pgsql'),
        'queue.connections.classification.connection' => 'queue_probe',
    ]);
}

/**
 * The classification jobs actually sitting on the queue, as a worker would find
 * them.
 *
 * @return list<ClassifyKnowledgeEntryJob>
 */
function queuedClassificationJobs(): array
{
    $connection = array_key_exists('queue_probe', (array) config('database.connections'))
        ? DB::connection('queue_probe')
        : DB::connection();

    return $connection->table('jobs')
        ->where('queue', 'classification')
        ->orderBy('id')
        ->pluck('payload')
        ->map(fn (string $payload) => unserialize(json_decode($payload, true)['data']['command']))
        ->values()
        ->all();
}

/**
 * @return list<int>
 */
function queuedClassificationEntryIds(): array
{
    return array_map(
        fn (ClassifyKnowledgeEntryJob $job): int => $job->entryId,
        queuedClassificationJobs(),
    );
}

it('creates a pending entry with tags, entities and relations', function () {
    ingestionMode(ImportanceClassifierMode::Off);
    Queue::fake();

    $entry = app(KnowledgeWriter::class)->store(
        projectId: 'p1',
        title: 'Use pgvector for search',
        content: '# note',
        category: 'design-decision',
        source: KnowledgeSource::Condense,
        tags: ['search', 'db'],
        entities: [['name' => 'pgvector', 'type' => 'library'], ['name' => 'HybridSearcher', 'type' => 'class']],
        relations: [['subject' => 'HybridSearcher', 'predicate' => 'uses', 'object' => 'pgvector']],
    );

    expect($entry->status)->toBe(KnowledgeStatus::Pending->value);
    expect($entry->source)->toBe('condense');
    expect($entry->tags()->count())->toBe(2);
    expect($entry->entities()->count())->toBe(2);
    expect(Relation::where('entry_id', $entry->id)->count())->toBe(1);
    expect(Entity::where('project_id', 'p1')->where('name', 'pgvector')->exists())->toBeTrue();
    expect(KnowledgeEntry::where('project_id', 'p1')->where('status', 'pending')->count())->toBe(1);
});

it('reuses an existing entity for relations referenced by name', function () {
    Queue::fake();
    Entity::create(['project_id' => 'p1', 'name' => 'HybridSearcher', 'type' => 'class']);

    app(KnowledgeWriter::class)->store('p1', 't', 'c', 'insight', KnowledgeSource::Condense,
        relations: [['subject' => 'HybridSearcher', 'predicate' => 'uses', 'object' => 'pgvector']]);

    // No duplicate typed entity created; a bare 'pgvector' placeholder is added.
    expect(Entity::where('project_id', 'p1')->where('name', 'HybridSearcher')->count())->toBe(1);
    expect(Entity::where('project_id', 'p1')->where('name', 'pgvector')->count())->toBe(1);
});

it('accepts a raw string source and normalizes it through the enum', function () {
    Queue::fake();

    $entry = app(KnowledgeWriter::class)->store('p1', 't', 'c', 'insight', 'import');

    expect($entry->source)->toBe(KnowledgeSource::Import->value)
        ->and($entry->status)->toBe(KnowledgeStatus::Pending->value);
    Queue::assertNotPushed(ClassifyKnowledgeEntryJob::class);
});

it('rejects an unknown source before anything is persisted', function () {
    Queue::fake();

    expect(fn () => app(KnowledgeWriter::class)->store(
        'p1', 'Rogue', 'c', 'insight', 'webhook', tags: ['t'], entities: [['name' => 'Order']],
    ))->toThrow(InvalidArgumentException::class, 'webhook');

    expect(KnowledgeEntry::count())->toBe(0);
    expect(Entity::count())->toBe(0);
    expect(DB::table('tags')->count())->toBe(0);
    Queue::assertNotPushed(ClassifyKnowledgeEntryJob::class);
});

it('creates a classifying entry and dispatches classification', function (KnowledgeSource $source, ImportanceClassifierMode $mode) {
    ingestionMode($mode);
    Queue::fake();

    $entry = app(KnowledgeWriter::class)->store('p1', 't', 'c', 'insight', $source);

    expect($entry->status)->toBe(KnowledgeStatus::Classifying->value)
        ->and($entry->fresh()->status)->toBe(KnowledgeStatus::Classifying->value);
    Queue::assertPushed(
        ClassifyKnowledgeEntryJob::class,
        fn (ClassifyKnowledgeEntryJob $job): bool => $job->entryId === (int) $entry->id,
    );
    // A `classifying` entry must not reach the index before its verdict.
    Queue::assertNotPushed(IndexEntryJob::class);
})->with([
    'condense in shadow' => [KnowledgeSource::Condense, ImportanceClassifierMode::Shadow],
    'condense in enforce' => [KnowledgeSource::Condense, ImportanceClassifierMode::Enforce],
    'mcp in shadow' => [KnowledgeSource::Mcp, ImportanceClassifierMode::Shadow],
    'mcp in enforce' => [KnowledgeSource::Mcp, ImportanceClassifierMode::Enforce],
    'cli in shadow' => [KnowledgeSource::Cli, ImportanceClassifierMode::Shadow],
    'cli in enforce' => [KnowledgeSource::Cli, ImportanceClassifierMode::Enforce],
]);

it('creates a pending entry and never dispatches classification', function (KnowledgeSource $source, ImportanceClassifierMode $mode) {
    ingestionMode($mode);
    Queue::fake();

    $entry = app(KnowledgeWriter::class)->store('p1', 't', 'c', 'insight', $source);

    expect($entry->status)->toBe(KnowledgeStatus::Pending->value)
        ->and($entry->fresh()->status)->toBe(KnowledgeStatus::Pending->value);
    Queue::assertNotPushed(ClassifyKnowledgeEntryJob::class);
    // A `pending` entry is immediately visible to search, so it is indexed.
    Queue::assertPushed(IndexEntryJob::class);
})->with([
    // `import` and `manual` never classify, whatever the mode.
    'import in shadow' => [KnowledgeSource::Import, ImportanceClassifierMode::Shadow],
    'import in enforce' => [KnowledgeSource::Import, ImportanceClassifierMode::Enforce],
    'import in off' => [KnowledgeSource::Import, ImportanceClassifierMode::Off],
    'manual in shadow' => [KnowledgeSource::Manual, ImportanceClassifierMode::Shadow],
    'manual in enforce' => [KnowledgeSource::Manual, ImportanceClassifierMode::Enforce],
    'manual in off' => [KnowledgeSource::Manual, ImportanceClassifierMode::Off],
    // `off` withholds classification from the three classified sources too.
    'condense in off' => [KnowledgeSource::Condense, ImportanceClassifierMode::Off],
    'mcp in off' => [KnowledgeSource::Mcp, ImportanceClassifierMode::Off],
    'cli in off' => [KnowledgeSource::Cli, ImportanceClassifierMode::Off],
]);

it('does not queue the classification job while the write is still uncommitted', function () {
    ingestionMode(ImportanceClassifierMode::Shadow);
    fakeIndexingOnly();
    useSeparateQueueConnection();

    $queuedMidTransaction = null;
    $entriesMidTransaction = null;

    $entry = DB::transaction(function () use (&$queuedMidTransaction, &$entriesMidTransaction) {
        $entry = app(KnowledgeWriter::class)->store(
            'p1', 'Atomic', 'c', 'insight', KnowledgeSource::Mcp,
            tags: ['billing'],
            entities: [['name' => 'Order', 'type' => 'concept']],
            relations: [['subject' => 'Order', 'predicate' => 'needs', 'object' => 'Approval']],
        );

        // The entry is written but not yet committed, so a worker on the queue
        // connection could not read it — and must therefore not be able to find
        // a job for it either.
        $entriesMidTransaction = DB::table('knowledge_entries')->count();
        $queuedMidTransaction = count(queuedClassificationJobs());

        return $entry;
    });

    expect($entriesMidTransaction)->toBe(1);
    expect($queuedMidTransaction)->toBe(0);

    // The job appears only once the write has committed.
    expect(queuedClassificationEntryIds())->toBe([(int) $entry->id]);
});

it('discards the classification job when the surrounding write is rolled back', function () {
    ingestionMode(ImportanceClassifierMode::Shadow);
    fakeIndexingOnly();
    useSeparateQueueConnection();

    expect(fn () => DB::transaction(function () {
        app(KnowledgeWriter::class)->store(
            'p1', 'Doomed', 'c', 'insight', KnowledgeSource::Mcp,
            tags: ['billing'],
            entities: [['name' => 'Order', 'type' => 'concept']],
            relations: [['subject' => 'Order', 'predicate' => 'needs', 'object' => 'Approval']],
        );

        throw new RuntimeException('the caller failed after the write');
    }))->toThrow(RuntimeException::class, 'the caller failed after the write');

    expect(KnowledgeEntry::count())->toBe(0);
    // An eagerly pushed job would have committed on the queue connection and
    // survived this rollback, pointing at an entry that never existed.
    expect(queuedClassificationJobs())->toBe([]);
});

it('rolls the entry, its tags and its entities back when a related write fails', function () {
    ingestionMode(ImportanceClassifierMode::Shadow);
    fakeIndexingOnly();
    useSeparateQueueConnection();

    // Fails the write once the entry, its tags and its entities are in the
    // transaction: exactly the partial state the job must never observe.
    Relation::creating(function () {
        throw new RuntimeException('relation write failed');
    });

    expect(fn () => app(KnowledgeWriter::class)->store(
        'p1', 'Doomed', 'c', 'insight', KnowledgeSource::Mcp,
        tags: ['billing'],
        entities: [['name' => 'Order', 'type' => 'concept']],
        relations: [['subject' => 'Order', 'predicate' => 'needs', 'object' => 'Approval']],
    ))->toThrow(RuntimeException::class, 'relation write failed');

    expect(KnowledgeEntry::count())->toBe(0);
    expect(Entity::count())->toBe(0);
    expect(Relation::count())->toBe(0);
    expect(DB::table('entry_tags')->count())->toBe(0);
    expect(queuedClassificationJobs())->toBe([]);
});

it('queues a classification job that observes the entry with every related record', function () {
    ingestionMode(ImportanceClassifierMode::Shadow);
    fakeIndexingOnly();

    $entry = app(KnowledgeWriter::class)->store(
        'p1', 'Complete', 'c', 'insight', KnowledgeSource::Cli,
        tags: ['billing'],
        entities: [['name' => 'Order', 'type' => 'concept']],
        relations: [['subject' => 'Order', 'predicate' => 'needs', 'object' => 'Approval']],
    );

    // Replay the queued payload exactly as a worker would, and inspect the
    // entry the job itself resolves.
    [$job] = queuedClassificationJobs();
    $observed = KnowledgeEntry::query()->findOrFail($job->entryId);

    expect($observed->id)->toBe($entry->id)
        ->and($observed->status)->toBe(KnowledgeStatus::Classifying->value)
        ->and($observed->tags->pluck('name')->all())->toBe(['billing'])
        ->and($observed->entities->pluck('name')->all())->toBe(['Order'])
        ->and(Relation::where('entry_id', $observed->id)->count())->toBe(1);
});
