<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Tools\Concerns\ResolvesProjectId;
use App\Models\Project;
use App\Models\ProjectPath;

beforeEach(function () {
    $this->resolver = new class
    {
        use ResolvesProjectId;
    };
});

it('returns explicit project_id when provided', function () {
    $project = Project::create([
        'id' => 'my-project',
        'name' => 'My Project',
        'root_path' => '/tmp/my-project',
    ]);

    $resolved = $this->resolver->resolveProjectId('my-project');

    expect($resolved)->toBe('my-project');
});

it('resolves by cwd via project_paths', function () {
    $project = Project::create([
        'id' => 'web-app',
        'name' => 'Web App',
        'root_path' => '/home/user/web-app',
    ]);
    ProjectPath::create([
        'project_id' => $project->id,
        'path' => '/home/user/web-app/src',
    ]);

    $resolved = $this->resolver->resolveProjectId(null, '/home/user/web-app/src/components');

    expect($resolved)->toBe('web-app');
});

it('slugifies basename of cwd when no project found', function () {
    $resolved = $this->resolver->resolveProjectId(null, '/home/user/My Cool Project');

    expect($resolved)->toBe('my-cool-project');
});

it('ensureProject creates project when it does not exist', function () {
    $pid = $this->resolver->ensureProject(null, '/home/user/new-project');

    expect($pid)->toBe('new-project');
    $this->assertDatabaseHas('projects', [
        'id' => 'new-project',
        'name' => 'new-project',
        'root_path' => '/home/user/new-project',
    ]);
});

it('ensureProject returns existing project id without creating', function () {
    Project::create([
        'id' => 'existing',
        'name' => 'Existing',
        'root_path' => '/tmp/existing',
    ]);

    $pid = $this->resolver->ensureProject('existing', '/tmp/existing');

    expect($pid)->toBe('existing');
    $this->assertDatabaseCount('projects', 1);
});
