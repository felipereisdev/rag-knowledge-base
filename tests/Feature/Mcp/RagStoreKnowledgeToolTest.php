<?php

use App\Mcp\Servers\RagServer;
use App\Mcp\Tools\RagStoreKnowledgeTool;
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
