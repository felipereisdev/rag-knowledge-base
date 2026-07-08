<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Project;
use App\Models\ProjectPath;
use Illuminate\Support\Str;

trait ResolvesProjectId
{
    /**
     * Resolve a project_id from explicit arg, cwd, or slugified basename.
     *
     * Mirrors the Python _resolve_project_id: if $projectId is null,
     * search project_paths for an ancestor of $cwd; if none found,
     * slugify the basename of $cwd.
     */
    public function resolveProjectId(?string $projectId, ?string $cwd = null): string
    {
        if ($projectId !== null && $projectId !== '') {
            return $projectId;
        }

        $cwd = $cwd ?? getcwd();

        // Search project_paths for an ancestor of $cwd (or an exact match).
        // A stored path is an ancestor of $cwd when $cwd starts with path + '/'.
        // Order by longest path so the most specific ancestor wins.
        $match = ProjectPath::query()
            ->where('path', $cwd)
            ->orWhereRaw('?::text LIKE path || ?::text', [$cwd, '/%'])
            ->orderByRaw('LENGTH(path) DESC')
            ->first();

        if ($match) {
            return $match->project_id;
        }

        return $this->slugify(basename($cwd));
    }

    /**
     * Ensure a project exists, creating it if needed. Returns project_id.
     *
     * Mirrors the Python _ensure_project.
     */
    public function ensureProject(?string $projectId, ?string $cwd = null): string
    {
        $pid = $this->resolveProjectId($projectId, $cwd);

        if (! Project::where('id', $pid)->exists()) {
            $cwd = $cwd ?? getcwd();
            Project::create([
                'id' => $pid,
                'name' => basename($cwd) ?: $pid,
                'root_path' => $cwd,
            ]);
        }

        return $pid;
    }

    public function slugify(string $text): string
    {
        $slug = Str::slug($text);

        return $slug !== '' ? $slug : 'project';
    }
}
