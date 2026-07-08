<?php

use App\Models\KnowledgeEntry;
use App\Models\Project;

describe('KnowledgeEntryResource', function () {
    it('can create an entry with defaults', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);

        $response = $this->post('/martis/api/resources/knowledge-entries', [
            'project_id' => $project->id,
            'title' => 'Test entry',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('knowledge_entries', [
            'project_id' => 'r1',
            'title' => 'Test entry',
            'status' => 'pending',
            'category' => 'insight',
        ]);
    });

    it('can list entries', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        KnowledgeEntry::create(['project_id' => $project->id, 'title' => 'E1']);
        KnowledgeEntry::create(['project_id' => $project->id, 'title' => 'E2']);

        $response = $this->get('/martis/api/resources/knowledge-entries');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    });

    it('can update entry status', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $entry = KnowledgeEntry::create(['project_id' => $project->id, 'title' => 'T']);

        $response = $this->put("/martis/api/resources/knowledge-entries/{$entry->id}", [
            'status' => 'approved',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('knowledge_entries', [
            'id' => $entry->id,
            'status' => 'approved',
        ]);
    });
});
