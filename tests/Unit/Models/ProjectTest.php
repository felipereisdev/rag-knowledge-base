<?php

use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Models\ProjectPath;

describe('Project model', function () {
    it('can be created with required fields', function () {
        $project = Project::create([
            'id' => 'my-repo',
            'name' => 'My Repo',
            'root_path' => '/path/to/repo',
        ]);

        expect($project->id)->toBe('my-repo')
            ->and($project->name)->toBe('My Repo')
            ->and($project->language)->toBe('en')
            ->and($project->description)->toBe('');
    });

    it('has many entries', function () {
        $project = Project::create([
            'id' => 'repo1',
            'name' => 'Repo 1',
            'root_path' => '/path',
        ]);
        KnowledgeEntry::create([
            'project_id' => $project->id,
            'title' => 'Test entry',
        ]);

        expect($project->entries)->toHaveCount(1);
    });

    it('has many paths', function () {
        $project = Project::create([
            'id' => 'repo1',
            'name' => 'Repo 1',
            'root_path' => '/path',
        ]);
        ProjectPath::create([
            'project_id' => $project->id,
            'path' => '/path/sub',
        ]);

        expect($project->paths)->toHaveCount(1);
    });

    it('cascades on delete', function () {
        $project = Project::create([
            'id' => 'repo1',
            'name' => 'Repo 1',
            'root_path' => '/path',
        ]);
        KnowledgeEntry::create([
            'project_id' => $project->id,
            'title' => 'Test',
        ]);

        $project->delete();

        expect(KnowledgeEntry::count())->toBe(0);
    });
});
