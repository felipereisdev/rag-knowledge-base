<?php

use App\Models\KnowledgeEntry;
use App\Models\Project;

it('directs operators to the indexing queue worker', function () {
    $project = Project::create([
        'id' => 'reindex-project',
        'name' => 'Reindex project',
        'root_path' => '/reindex-project',
    ]);
    KnowledgeEntry::create([
        'project_id' => $project->id,
        'title' => 'Entry',
        'content' => 'Content',
        'status' => 'approved',
    ]);

    $this->artisan('rag:reindex', ['--force' => true])
        ->expectsOutputToContain("Run 'php artisan queue:work --queue=indexing' to process them.")
        ->assertSuccessful();
});
