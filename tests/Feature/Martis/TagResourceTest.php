<?php

use App\Models\Project;
use App\Models\Tag;

describe('TagResource', function () {
    it('can list tags', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        Tag::create(['project_id' => $project->id, 'name' => 'php']);
        Tag::create(['project_id' => $project->id, 'name' => 'laravel']);

        $response = $this->get('/martis/api/resources/tags');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    });

    it('can create a tag', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);

        $response = $this->post('/martis/api/resources/tags', [
            'project_id' => $project->id,
            'name' => 'martis',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('tags', [
            'project_id' => 'r1',
            'name' => 'martis',
        ]);
    });

    it('can delete a tag', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $tag = Tag::create(['project_id' => $project->id, 'name' => 'todelete']);

        $response = $this->delete("/martis/api/resources/tags/{$tag->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
    });
});
