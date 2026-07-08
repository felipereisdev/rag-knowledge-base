<?php

use App\Models\Entity;
use App\Models\Project;

describe('EntityResource', function () {
    it('can list entities', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        Entity::create(['project_id' => $project->id, 'name' => 'User']);
        Entity::create(['project_id' => $project->id, 'name' => 'Post']);

        $response = $this->get('/martis/api/resources/entities');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    });

    it('can create an entity', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);

        $response = $this->post('/martis/api/resources/entities', [
            'project_id' => $project->id,
            'name' => 'Comment',
            'type' => 'model',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('entities', [
            'project_id' => 'r1',
            'name' => 'Comment',
            'type' => 'model',
        ]);
    });

    it('can delete an entity', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $entity = Entity::create(['project_id' => $project->id, 'name' => 'ToDelete']);

        $response = $this->delete("/martis/api/resources/entities/{$entity->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('entities', ['id' => $entity->id]);
    });
});
