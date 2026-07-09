<?php

namespace App\Http\Controllers;

use App\Mcp\Tools\Concerns\ResolvesProjectId;
use App\Models\KnowledgeEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class HookController extends Controller
{
    use ResolvesProjectId;

    public function ensure(Request $request): Response
    {
        $cwd = (string) $request->input('cwd', '');
        // Call the trait's ensureProject(): resolves from cwd and creates the Project.
        $pid = $this->ensureProject(null, $cwd !== '' ? $cwd : null);

        return response($pid."\n", 200)->header('Content-Type', 'text/plain');
    }

    public function digest(Request $request): Response
    {
        $cwd = (string) $request->input('cwd', '');
        $limit = (int) $request->input('limit', 20);
        $pid = $this->resolveProjectId(null, $cwd !== '' ? $cwd : null);

        $entries = KnowledgeEntry::with('tags')
            ->where('project_id', $pid)
            ->where('status', 'approved')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $lines = $entries->map(function (KnowledgeEntry $e): string {
            $tags = $e->tags->pluck('name')->all();
            $tagStr = $tags !== [] ? ' · tags: '.implode(', ', $tags) : '';

            return "- {$e->title} ({$e->category}){$tagStr}";
        })->all();

        return response(implode("\n", $lines), 200)->header('Content-Type', 'text/plain');
    }
}
