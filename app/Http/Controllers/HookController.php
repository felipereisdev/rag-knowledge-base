<?php

namespace App\Http\Controllers;

use App\Mcp\Tools\Concerns\ResolvesProjectId;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Services\Search\HybridSearcher;
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

    public function search(Request $request): Response
    {
        $cwd = (string) $request->input('cwd', '');
        $query = (string) $request->input('query', '');
        $limit = (int) $request->input('limit', 3);
        $minScore = (float) $request->input('min_score', 0.40);

        $pid = $this->resolveProjectId(null, $cwd !== '' ? $cwd : null);

        $project = Project::find($pid);
        if (! $project) {
            return $this->plain('');
        }

        $approved = KnowledgeEntry::where('project_id', $pid)->where('status', 'approved')->count();
        if ($approved === 0) {
            return $this->plain('');
        }

        $searcher = app()->makeWith(HybridSearcher::class, [
            'limit' => $limit,
            'minScore' => $minScore,
            'expandGraph' => true,
        ]);

        $results = $searcher->search($query, $pid, $request->input('category'));
        if ($results === []) {
            return $this->plain('');
        }

        $lines = [];
        foreach ($results as $i => $r) {
            $tags = $r->tags !== [] ? ' ['.implode(', ', $r->tags).']' : '';
            $lines[] = '  ['.($i + 1)."] {$r->title} ({$r->category}){$tags} (score: {$r->score})";
            $lines[] = "      {$r->snippet}";
        }

        return $this->plain(implode("\n", $lines));
    }

    private function plain(string $body): Response
    {
        return response($body, 200)->header('Content-Type', 'text/plain');
    }
}
