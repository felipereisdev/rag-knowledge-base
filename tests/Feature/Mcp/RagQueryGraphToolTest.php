<?php

use App\Mcp\Servers\RagServer;
use App\Mcp\Tools\RagQueryGraphTool;
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

it('returns entity and relations for a known entity', function () {
    $response = RagServer::tool(RagQueryGraphTool::class, [
        'project_id' => 'test-project',
        'entity' => 'Order',
        'depth' => 1,
    ]);

    $response->assertOk();

    $response->assertSee('Entity: Order');
    $response->assertSee('Order —requires→ Manager');
});

it('suggests known entities when seed not found', function () {
    $response = RagServer::tool(RagQueryGraphTool::class, [
        'project_id' => 'test-project',
        'entity' => 'Nonexistent',
    ]);

    $response->assertOk();
    $response->assertSee("No entity named 'Nonexistent'");
    $response->assertSee('Known entities:');
    $response->assertSee('Order');
});

it('returns not-found for unknown project', function () {
    $response = RagServer::tool(RagQueryGraphTool::class, [
        'project_id' => 'nonexistent',
        'entity' => 'Anything',
    ]);

    $response->assertOk();
    $response->assertSee("Project 'nonexistent' not found");
});
