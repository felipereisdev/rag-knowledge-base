<?php

use App\Models\Entity;
use App\Models\Project;
use App\Models\Relation;
use App\Services\Graph\GraphExplorer;

beforeEach(function () {
    $this->project = Project::create([
        'id' => 'test-project',
        'name' => 'Test',
        'root_path' => '/tmp/test',
    ]);

    $this->order = Entity::create(['project_id' => $this->project->id, 'name' => 'Order', 'type' => 'concept']);
    $this->manager = Entity::create(['project_id' => $this->project->id, 'name' => 'Manager', 'type' => 'role']);
    $this->approval = Entity::create(['project_id' => $this->project->id, 'name' => 'Approval', 'type' => 'concept']);

    Relation::create([
        'project_id' => $this->project->id,
        'subject_id' => $this->order->id,
        'predicate' => 'requires',
        'object_id' => $this->manager->id,
    ]);

    Relation::create([
        'project_id' => $this->project->id,
        'subject_id' => $this->manager->id,
        'predicate' => 'performs',
        'object_id' => $this->approval->id,
    ]);
});

it('returns seed entity and direct neighbors at depth 1', function () {
    $explorer = new GraphExplorer;
    $result = $explorer->explore($this->project->id, 'Order', 1);

    expect($result['entity']['name'])->toBe('Order')
        ->and($result['entities'])->toHaveCount(2) // Order + Manager
        ->and($result['relations'])->toHaveCount(1)
        ->and($result['relations'][0]['predicate'])->toBe('requires');
});

it('expands to 2 hops at depth 2', function () {
    $explorer = new GraphExplorer;
    $result = $explorer->explore($this->project->id, 'Order', 2);

    expect($result['entities'])->toHaveCount(3) // Order + Manager + Approval
        ->and($result['relations'])->toHaveCount(2);
});

it('returns null entity when seed not found', function () {
    $explorer = new GraphExplorer;
    $result = $explorer->explore($this->project->id, 'Nonexistent', 1);

    expect($result['entity'])->toBeNull()
        ->and($result['entities'])->toBeEmpty()
        ->and($result['relations'])->toBeEmpty();
});

it('clamps depth to [1, 2]', function () {
    $explorer = new GraphExplorer;

    $r1 = $explorer->explore($this->project->id, 'Order', 0);
    $r2 = $explorer->explore($this->project->id, 'Order', 99);

    expect($r1['entities'])->toHaveCount(2) // depth clamped to 1
        ->and($r2['entities'])->toHaveCount(3); // depth clamped to 2
});

it('returns empty graph for empty project', function () {
    $explorer = new GraphExplorer;
    $result = $explorer->explore($this->project->id, 'Order', 1);

    // Sanity: the project has data, so this should NOT be empty.
    expect($result['entities'])->not->toBeEmpty();

    // Now test a truly empty project.
    $empty = Project::create([
        'id' => 'empty',
        'name' => 'Empty',
        'root_path' => '/tmp/empty',
    ]);

    $result2 = $explorer->explore($empty->id, 'Anything', 1);

    expect($result2['entity'])->toBeNull()
        ->and($result2['entities'])->toBeEmpty();
});
