<?php

namespace App\Mcp\Tools\Concerns;

use App\Exceptions\ProjectNotIdentifiedException;
use App\Models\Project;
use App\Models\ProjectPath;
use Illuminate\Support\Str;

trait ResolvesProjectId
{
    /**
     * Resolve a project_id from an explicit arg, the MCP URL segment, or cwd.
     *
     * Precedence:
     *   1. explicit $projectId argument
     *   2. the {project} segment of a project-scoped MCP URL (/mcp/rag/{project})
     *   3. $cwd — an explicitly supplied client working directory (the hooks pass
     *      this): match a ProjectPath ancestor, else slugify its basename
     *
     * If none of these identify a project, we throw. The server runs as a shared
     * HTTP service and cannot see the client filesystem, so falling back to the
     * server's own getcwd() would silently resolve to the container's docroot
     * (e.g. "public") — a wrong, shared bucket. Failing loudly is safer.
     *
     * @throws ProjectNotIdentifiedException when no identity can be determined
     */
    public function resolveProjectId(?string $projectId, ?string $cwd = null): string
    {
        if ($projectId !== null && $projectId !== '') {
            return $projectId;
        }

        $routeProject = $this->routeProjectId();
        if ($routeProject !== null) {
            return $routeProject;
        }

        if ($cwd !== null && $cwd !== '') {
            // Search project_paths for an ancestor of $cwd (or an exact match).
            // A stored path is an ancestor of $cwd when $cwd starts with path
            // and the next character is '/'. Use strpos + substring instead of
            // LIKE so '_' and '%' in stored paths are literal, not wildcards.
            // Order by longest path so the most specific ancestor wins.
            $match = ProjectPath::query()
                ->where('path', $cwd)
                ->orWhereRaw(
                    'strpos(?, path) = 1 AND substring(? FROM length(path) + 1 FOR 1) = ?',
                    [$cwd, $cwd, '/']
                )
                ->orderByRaw('LENGTH(path) DESC')
                ->first();

            if ($match) {
                return $match->project_id;
            }

            return $this->slugify(basename($cwd));
        }

        throw new ProjectNotIdentifiedException;
    }

    /**
     * Ensure a project exists, creating it if needed. Returns project_id.
     *
     * @throws ProjectNotIdentifiedException when no identity can be determined
     */
    public function ensureProject(?string $projectId, ?string $cwd = null): string
    {
        $pid = $this->resolveProjectId($projectId, $cwd);

        if (! Project::where('id', $pid)->exists()) {
            Project::create([
                'id' => $pid,
                'name' => ($cwd !== null && $cwd !== '') ? (basename($cwd) ?: $pid) : $pid,
                'root_path' => $cwd ?? '',
            ]);
        }

        return $pid;
    }

    /**
     * The {project} segment of a project-scoped MCP URL, or null.
     *
     * Present only when the client connected via /mcp/rag/{project}; absent for
     * the bare /mcp/rag route and outside an HTTP request (CLI, tests).
     */
    protected function routeProjectId(): ?string
    {
        $project = request()->route('project');

        return is_string($project) && $project !== '' ? $project : null;
    }

    public function slugify(string $text): string
    {
        $slug = Str::slug($text);

        return $slug !== '' ? $slug : 'project';
    }
}
