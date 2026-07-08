<?php

use App\Mcp\Servers\RagServer;
use App\Mcp\Tools\RagOpenApprovalUiTool;
use App\Models\Project;

beforeEach(function () {
    $this->project = Project::create([
        'id' => 'test-project',
        'name' => 'Test',
        'root_path' => '/tmp/test',
    ]);
});

it('returns the approval URL for the project', function () {
    $response = RagServer::tool(RagOpenApprovalUiTool::class, [
        'project_id' => 'test-project',
    ]);

    $response->assertOk();

    $response->assertSee('/martis/resources/knowledge-entries');
    $response->assertSee('filter[status]=pending');
});

it('resolves project from cwd when project_id omitted', function () {
    $response = RagServer::tool(RagOpenApprovalUiTool::class, [
        'cwd' => '/tmp/cwd-approval-project',
    ]);

    $response->assertOk();
    $response->assertSee('cwd-approval-project');
});
