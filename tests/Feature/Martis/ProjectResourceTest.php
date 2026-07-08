<?php

use App\Models\Project;

describe('ProjectResource', function () {
    it('can list projects', function () {
        Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);

        $response = $this->get('/martis/api/resources/projects');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    });

    it('can create a project', function () {
        $response = $this->post('/martis/api/resources/projects', [
            'id' => 'new-repo',
            'name' => 'New Repo',
            'root_path' => '/path/to/repo',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('projects', ['id' => 'new-repo']);
    });

    it('can update a project', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);

        $response = $this->put("/martis/api/resources/projects/{$project->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('projects', ['id' => 'r1', 'name' => 'Updated Name']);
    });

    it('can delete a project', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);

        $response = $this->delete("/martis/api/resources/projects/{$project->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('projects', ['id' => 'r1']);
    });
});
