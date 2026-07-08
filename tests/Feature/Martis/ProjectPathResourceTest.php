<?php

use App\Models\Project;
use App\Models\ProjectPath;

describe('ProjectPathResource', function () {
    it('can list project paths', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        ProjectPath::create(['project_id' => $project->id, 'path' => '/app']);
        ProjectPath::create(['project_id' => $project->id, 'path' => '/config']);

        $response = $this->get('/martis/api/resources/project-paths');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    });

    it('can create a project path', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);

        $response = $this->post('/martis/api/resources/project-paths', [
            'project_id' => $project->id,
            'path' => '/database/migrations',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('project_paths', [
            'project_id' => 'r1',
            'path' => '/database/migrations',
        ]);
    });

    it('can delete a project path', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $path = ProjectPath::create(['project_id' => $project->id, 'path' => '/tmp-remove']);

        $response = $this->delete("/martis/api/resources/project-paths/{$path->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('project_paths', ['id' => $path->id]);
    });
});
