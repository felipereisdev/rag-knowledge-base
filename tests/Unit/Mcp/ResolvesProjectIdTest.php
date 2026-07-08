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

it('resolves to the longest matching ancestor when multiple project_paths match', function () {
    $parent = Project::create([
        'id' => 'parent',
        'name' => 'Parent',
        'root_path' => '/home/user/parent',
    ]);
    $child = Project::create([
        'id' => 'child',
        'name' => 'Child',
        'root_path' => '/home/user/parent/child',
    ]);
    ProjectPath::create([
        'project_id' => $parent->id,
        'path' => '/home/user/parent',
    ]);
    ProjectPath::create([
        'project_id' => $child->id,
        'path' => '/home/user/parent/child',
    ]);

    // cwd sits under both registered paths; the longer (more specific) one wins.
    $resolved = $this->resolver->resolveProjectId(null, '/home/user/parent/child/src');

    expect($resolved)->toBe('child');
});

it('does not treat underscores in stored paths as LIKE wildcards', function () {
    $project = Project::create([
        'id' => 'under-score',
        'name' => 'Under Score',
        'root_path' => '/home/user/my_project',
    ]);
    ProjectPath::create([
        'project_id' => $project->id,
        'path' => '/home/user/my_project',
    ]);

    // cwd has 'X' where the stored path has '_'. A LIKE query would match
    // (underscore is a single-char wildcard); strpos treats it literally.
    $resolved = $this->resolver->resolveProjectId(null, '/home/user/myXproject/src');

    expect($resolved)->toBe('src'); // falls through to slugified basename
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
