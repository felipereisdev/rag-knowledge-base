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

    it('can update a project with empty optional fields (no not-null violation)', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);

        // The full drawer payload with description/project_type left empty — the
        // form sends these as null, which the NOT NULL columns must tolerate.
        $response = $this->putJson("/martis/api/resources/projects/{$project->id}", [
            'id' => 'r1',
            'name' => 'R1',
            'root_path' => '/tmp/r1',
            'description' => null,
            'project_type' => null,
            'language' => 'pt',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('projects', [
            'id' => 'r1',
            'root_path' => '/tmp/r1',
            'description' => '',
            'project_type' => '[]',
            'language' => 'pt',
        ]);
    });

    it('ignores slug (id) changes on update — the field is immutable', function () {
        Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);

        $response = $this->putJson('/martis/api/resources/projects/r1', [
            'id' => 'renamed',
            'name' => 'R1',
            'root_path' => '/p',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('projects', ['id' => 'r1']);
        $this->assertDatabaseMissing('projects', ['id' => 'renamed']);
    });

    it('persists selected project_type languages as a JSON array', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);

        $response = $this->putJson("/martis/api/resources/projects/{$project->id}", [
            'name' => 'R1',
            'root_path' => '/p',
            'project_type' => ['python', 'go'],
            'language' => 'en',
        ]);

        $response->assertOk();
        // MultiSelect fill() encodes to a JSON string; the column stores it verbatim.
        expect($project->fresh()->project_type)->toBe('["python","go"]');
        $this->assertDatabaseHas('projects', ['id' => 'r1', 'project_type' => '["python","go"]']);
    });

    it('can delete a project', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);

        $response = $this->delete("/martis/api/resources/projects/{$project->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('projects', ['id' => 'r1']);
    });
});
