<?php

use App\Mcp\Servers\RagServer;
use App\Mcp\Tools\RagStoreKnowledgeTool;
use App\Models\Entity;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Models\Relation;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;

beforeEach(function () {
    Queue::fake();
    // The local-embedder provider is configured for 768 dimensions.
    $fakeVector = array_fill(0, 768, 0.1);
    Embeddings::fake([
        [$fakeVector],
    ]);

    $this->project = Project::create([
        'id' => 'test-project',
        'name' => 'Test Project',
        'root_path' => '/tmp/test-project',
        'language' => 'en',
    ]);
});

it('stores a knowledge entry with tags and entities', function () {
    $response = RagServer::tool(RagStoreKnowledgeTool::class, [
        'project_id' => 'test-project',
        'title' => 'Orders over 1000 require manager approval',
        'content' => '## Rule'."\n\n".'Any order with total > 1000 must be approved by a manager before shipping.',
        'category' => 'business-rule',
        'tags' => ['orders', 'approval'],
        'entities' => [
            ['name' => 'Order', 'type' => 'concept'],
            ['name' => 'Manager', 'type' => 'role'],
        ],
        'relations' => [
            ['subject' => 'Order', 'predicate' => 'requires', 'object' => 'Manager'],
        ],
    ]);

    $response->assertOk();

    $entry = KnowledgeEntry::where('title', 'Orders over 1000 require manager approval')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->status)->toBe('pending')
        ->and($entry->category)->toBe('business-rule');

    expect($entry->tags->pluck('name')->all())->toBe(['orders', 'approval']);
    expect($entry->entities->pluck('name')->all())->toBe(['Order', 'Manager']);

    $relation = Relation::where('entry_id', $entry->id)->first();
    expect($relation)->not->toBeNull()
        ->and($relation->predicate)->toBe('requires');

    $response->assertSee('Knowledge entry stored (pending approval)');
    $response->assertSee($entry->id);
});

it('skips malformed entities and reports count', function () {
    $response = RagServer::tool(RagStoreKnowledgeTool::class, [
        'project_id' => 'test-project',
        'title' => 'Test entry',
        'content' => 'Content',
        'entities' => [
            ['name' => 'Valid'],
            ['type' => 'invalid'], // missing name
            'not-a-dict',
        ],
    ]);

    $response->assertOk();

    $response->assertSee('2 malformed items skipped');
    $this->assertDatabaseHas('entities', ['name' => 'Valid']);
});

it('auto-creates project from cwd when project_id omitted', function () {
    $response = RagServer::tool(RagStoreKnowledgeTool::class, [
        'title' => 'Test',
        'content' => 'Content',
        'cwd' => '/tmp/new-project',
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('projects', ['id' => 'new-project']);
    $this->assertDatabaseHas('knowledge_entries', ['title' => 'Test']);
});

it('does not duplicate a typed entity when a relation references it by name', function () {
    // First call: store an entity with a non-empty type.
    RagServer::tool(RagStoreKnowledgeTool::class, [
        'project_id' => 'test-project',
        'title' => 'Typed entity',
        'content' => 'Defines the Order concept.',
        'entities' => [
            ['name' => 'Order', 'type' => 'concept'],
        ],
    ]);

    // Sanity check: the typed entity exists exactly once.
    expect(Entity::where('project_id', 'test-project')->where('name', 'Order')->count())->toBe(1);
    expect(Entity::where('project_id', 'test-project')->where('name', 'Order')->first()->type)->toBe('concept');

    // Second call: store a relation referencing Order by name only.
    RagServer::tool(RagStoreKnowledgeTool::class, [
        'project_id' => 'test-project',
        'title' => 'Order relation',
        'content' => 'An Order is fulfilled by a Warehouse.',
        'relations' => [
            ['subject' => 'Order', 'predicate' => 'fulfilled_by', 'object' => 'Warehouse'],
        ],
    ]);

    // Regression: the relation lookup must reuse the existing typed Order
    // entity rather than creating a duplicate row with type=''. Only ONE
    // Order entity should exist, and it must still carry type='concept'.
    expect(Entity::where('project_id', 'test-project')->where('name', 'Order')->count())->toBe(1);
    expect(Entity::where('project_id', 'test-project')->where('name', 'Order')->first()->type)->toBe('concept');

    // The relation's subject_id must point at the original typed entity.
    $relation = Relation::where('predicate', 'fulfilled_by')->first();
    expect($relation)->not->toBeNull()
        ->and($relation->subject_id)->toBe(Entity::where('name', 'Order')->first()->id);

    // Warehouse did not pre-exist, so it should be created once with type=''.
    expect(Entity::where('project_id', 'test-project')->where('name', 'Warehouse')->count())->toBe(1);
    expect(Entity::where('project_id', 'test-project')->where('name', 'Warehouse')->first()->type)->toBe('');
});

it('preserves distinct types when the same entity name is stored with different types', function () {
    // The unique constraint is on (project_id, name, type), so two rows with
    // the same name but different types are legal and must both be preserved.
    RagServer::tool(RagStoreKnowledgeTool::class, [
        'project_id' => 'test-project',
        'title' => 'Order as concept',
        'content' => 'Order concept.',
        'entities' => [
            ['name' => 'Order', 'type' => 'concept'],
        ],
    ]);

    RagServer::tool(RagStoreKnowledgeTool::class, [
        'project_id' => 'test-project',
        'title' => 'Order as command',
        'content' => 'Order command.',
        'entities' => [
            ['name' => 'Order', 'type' => 'command'],
        ],
    ]);

    expect(Entity::where('project_id', 'test-project')->where('name', 'Order')->count())->toBe(2);
    expect(Entity::where('project_id', 'test-project')->where('name', 'Order')->pluck('type')->sort()->values()->all())
        ->toBe(['command', 'concept']);
});
