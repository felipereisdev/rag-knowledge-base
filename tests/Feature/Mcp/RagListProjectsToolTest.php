<?php

use App\Mcp\Servers\RagServer;
use App\Mcp\Tools\RagListProjectsTool;
use App\Models\KnowledgeEntry;
use App\Models\Project;

beforeEach(function () {
    $this->p1 = Project::create([
        'id' => 'project-one',
        'name' => 'Project One',
        'root_path' => '/tmp/p1',
        'language' => 'en',
    ]);
    $this->p2 = Project::create([
        'id' => 'project-two',
        'name' => 'Project Two',
        'root_path' => '/tmp/p2',
        'language' => 'pt-BR',
    ]);

    KnowledgeEntry::create([
        'project_id' => $this->p1->id,
        'title' => 'Entry 1',
        'content' => 'Content',
        'status' => 'approved',
    ]);
    KnowledgeEntry::create([
        'project_id' => $this->p1->id,
        'title' => 'Entry 2',
        'content' => 'Content',
        'status' => 'pending',
    ]);
});

it('lists all projects with stats', function () {
    $response = RagServer::tool(RagListProjectsTool::class, []);

    $response->assertOk();

    $response->assertSee('Project One (project-one)');
    $response->assertSee('Project Two (project-two)');
    $response->assertSee('Approved: 1');
    $response->assertSee('Pending: 1');
});

it('returns message when no projects exist', function () {
    Project::query()->delete();

    $response = RagServer::tool(RagListProjectsTool::class, []);

    $response->assertOk();
    $response->assertSee('No projects registered');
});
