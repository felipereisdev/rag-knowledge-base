<?php

use App\Models\Entity;
use App\Models\Project;
use App\Models\Relation;

describe('RelationResource', function () {
    it('can list relations', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $subject = Entity::create(['project_id' => $project->id, 'name' => 'Subj']);
        $object = Entity::create(['project_id' => $project->id, 'name' => 'Obj']);
        Relation::create([
            'project_id' => $project->id,
            'subject_id' => $subject->id,
            'predicate' => 'depends_on',
            'object_id' => $object->id,
        ]);

        $response = $this->get('/martis/api/resources/relations');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    });

    it('can create a relation', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $subject = Entity::create(['project_id' => $project->id, 'name' => 'Subject']);
        $object = Entity::create(['project_id' => $project->id, 'name' => 'Object']);

        $response = $this->post('/martis/api/resources/relations', [
            'project_id' => $project->id,
            'subject_id' => $subject->id,
            'predicate' => 'depends_on',
            'object_id' => $object->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('relations', [
            'project_id' => 'r1',
            'subject_id' => $subject->id,
            'predicate' => 'depends_on',
            'object_id' => $object->id,
        ]);
    });

    it('can delete a relation', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $subject = Entity::create(['project_id' => $project->id, 'name' => 'Subj']);
        $object = Entity::create(['project_id' => $project->id, 'name' => 'Obj']);
        $relation = Relation::create([
            'project_id' => $project->id,
            'subject_id' => $subject->id,
            'predicate' => 'uses',
            'object_id' => $object->id,
        ]);

        $response = $this->delete("/martis/api/resources/relations/{$relation->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('relations', ['id' => $relation->id]);
    });
});
