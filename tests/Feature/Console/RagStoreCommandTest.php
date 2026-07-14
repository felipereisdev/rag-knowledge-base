<?php

use App\Enums\ImportanceClassifierMode;
use App\Enums\KnowledgeSource;
use App\Enums\KnowledgeStatus;
use App\Jobs\ClassifyKnowledgeEntryJob;
use App\Models\Entity;
use App\Models\ImportanceClassifierSetting;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Models\Relation;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;

beforeEach(function () {
    Queue::fake();

    $fakeVector = array_fill(0, 768, 0.1);
    Embeddings::fake([[$fakeVector]]);
});

function commandMode(ImportanceClassifierMode $mode): void
{
    ImportanceClassifierSetting::query()->findOrFail(1)->update(['mode' => $mode->value]);
}

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
        ->and($entry->source)->toBe(KnowledgeSource::Cli->value)
        ->and($entry->tags->pluck('name')->all())->toBe(['tag1', 'tag2']);
});

/**
 * The command no longer writes anything itself: it parses options and hands
 * them to KnowledgeWriter with KnowledgeSource::Cli. That delegation is what
 * these three properties, together, can only be explained by — the writer is
 * the only code that consults the ingestion policy, is the only code that
 * dispatches ClassifyKnowledgeEntryJob, and owns the tag/entity/relation graph.
 */
it('delegates to the knowledge writer with the cli source', function () {
    Project::create(['id' => 'test-project', 'name' => 'Test', 'root_path' => '/tmp/test']);
    commandMode(ImportanceClassifierMode::Shadow);

    $this->artisan('rag:store', [
        'title' => 'Delegated Rule',
        '--project' => 'test-project',
        '--content' => 'Body.',
        '--category' => 'business-rule',
        '--tags' => 'billing, orders',
        '--entities' => 'Order, Invoice',
        '--relations' => 'Order:bills:Invoice',
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('classifying importance');

    $entry = KnowledgeEntry::where('title', 'Delegated Rule')->firstOrFail();

    // 1. The source the command declared, and the status the policy derives for
    //    it — the command sets neither by hand any more.
    expect($entry->source)->toBe(KnowledgeSource::Cli->value)
        ->and($entry->status)->toBe(KnowledgeStatus::Classifying->value);

    // 2. Only the writer dispatches classification.
    Queue::assertPushed(
        ClassifyKnowledgeEntryJob::class,
        fn (ClassifyKnowledgeEntryJob $job): bool => $job->entryId === (int) $entry->id,
    );

    // 3. The graph the command parsed, written by the writer.
    expect($entry->tags->pluck('name')->all())->toBe(['billing', 'orders'])
        ->and($entry->entities->pluck('name')->all())->toBe(['Order', 'Invoice'])
        ->and(Relation::where('entry_id', $entry->id)->first()->predicate)->toBe('bills');
    expect(Entity::where('project_id', 'test-project')->where('name', 'Invoice')->count())->toBe(1);
});

it('stores a pending entry and dispatches nothing when the classifier is off', function () {
    Project::create(['id' => 'test-project', 'name' => 'Test', 'root_path' => '/tmp/test']);
    commandMode(ImportanceClassifierMode::Off);

    $this->artisan('rag:store', [
        'title' => 'Unclassified Rule',
        '--project' => 'test-project',
        '--content' => 'Body.',
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('pending approval');

    expect(KnowledgeEntry::where('title', 'Unclassified Rule')->firstOrFail()->status)
        ->toBe(KnowledgeStatus::Pending->value);
    Queue::assertNotPushed(ClassifyKnowledgeEntryJob::class);
});

it('reuses an existing entity when a relation references it by name', function () {
    Project::create(['id' => 'test-project', 'name' => 'Test', 'root_path' => '/tmp/test']);
    Entity::create(['project_id' => 'test-project', 'name' => 'Order', 'type' => 'concept']);

    $this->artisan('rag:store', [
        'title' => 'Relation Rule',
        '--project' => 'test-project',
        '--content' => 'Body.',
        '--relations' => 'Order:bills:Invoice',
    ])->assertSuccessful();

    // The writer's relation lookup ignores type, so the typed entity is reused
    // rather than duplicated with type=''.
    $orders = Entity::where('project_id', 'test-project')->where('name', 'Order')->get();
    expect($orders)->toHaveCount(1)
        ->and($orders->first()->type)->toBe('concept');
    expect(Relation::where('predicate', 'bills')->first()->subject_id)->toBe($orders->first()->id);
});

/**
 * `--entities` never supplies a type, so a bare `--entities=Order` must not
 * fragment an existing typed `Order` into two nodes. It must also resolve to
 * the SAME node a relation naming `Order` in the same call resolves to —
 * otherwise the entry attaches to one `Order` while the relation's subject
 * points at another.
 */
it('reuses an existing typed entity when --entities supplies no type', function () {
    Project::create(['id' => 'test-project', 'name' => 'Test', 'root_path' => '/tmp/test']);
    Entity::create(['project_id' => 'test-project', 'name' => 'Order', 'type' => 'concept']);

    $this->artisan('rag:store', [
        'title' => 'Entity Collision Rule',
        '--project' => 'test-project',
        '--content' => 'Body.',
        '--entities' => 'Order',
        '--relations' => 'Order:bills:Invoice',
    ])->assertSuccessful();

    $orders = Entity::where('project_id', 'test-project')->where('name', 'Order')->get();
    expect($orders)->toHaveCount(1)
        ->and($orders->first()->type)->toBe('concept');

    $entry = KnowledgeEntry::where('title', 'Entity Collision Rule')->firstOrFail();
    expect($entry->entities->pluck('id')->all())->toBe([$orders->first()->id])
        ->and(Relation::where('predicate', 'bills')->first()->subject_id)->toBe($orders->first()->id);
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
