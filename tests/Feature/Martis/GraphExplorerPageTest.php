<?php

use App\Models\Entity;
use App\Models\Project;
use App\Models\Relation;

beforeEach(function () {
    $this->project = Project::create([
        'id' => 'test-project',
        'name' => 'Test',
        'root_path' => '/tmp/test',
    ]);

    $order = Entity::create(['project_id' => $this->project->id, 'name' => 'Order', 'type' => 'concept']);
    $manager = Entity::create(['project_id' => $this->project->id, 'name' => 'Manager', 'type' => 'role']);

    Relation::create([
        'project_id' => $this->project->id,
        'subject_id' => $order->id,
        'predicate' => 'requires',
        'object_id' => $manager->id,
    ]);
});

it('renders the graph explorer page', function () {
    $response = $this->get('/martis/graph');

    $response->assertOk();
    $response->assertSee('Knowledge Graph Explorer');
});

it('returns graph data as JSON via the data endpoint', function () {
    $response = $this->getJson('/martis/graph/data?project_id=test-project');

    $response->assertOk();
    $response->assertJsonStructure([
        'nodes',
        'edges',
    ]);

    $data = $response->json();
    expect($data['nodes'])->toHaveCount(2)
        ->and($data['edges'])->toHaveCount(1)
        ->and($data['edges'][0]['label'])->toBe('requires');
});

it('returns all entities when no project_id filter is passed', function () {
    $other = Project::create([
        'id' => 'other-project',
        'name' => 'Other',
        'root_path' => '/tmp/other',
    ]);
    Entity::create(['project_id' => $other->id, 'name' => 'Invoice', 'type' => 'concept']);

    $response = $this->getJson('/martis/graph/data');

    $response->assertOk();
    expect($response->json('nodes'))->toHaveCount(3);
});
