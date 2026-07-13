<?php

namespace App\Http\Controllers;

use App\Exceptions\ProjectNotIdentifiedException;
use App\Jobs\CondenseSessionJob;
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

    public function condense(Request $request): Response
    {
        $cwd = (string) $request->input('cwd', '');
        $sessionId = (string) $request->input('session_id', '');
        $transcriptPath = (string) $request->input('transcript_path', '');

        if ($sessionId === '' || $transcriptPath === '') {
            return response('', 202);
        }

        try {
            $pid = $this->ensureProject(null, $cwd !== '' ? $cwd : null);
        } catch (ProjectNotIdentifiedException) {
            return response('', 202);
        }

        CondenseSessionJob::dispatch($pid, $transcriptPath, $sessionId);

        return response('', 202);
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
            $signals = __('rag.search.fusion').': '.number_format($r->fusionScore, 4);
            if ($r->semanticSimilarity !== null) {
                $signals .= ', '.__('rag.search.semantic').': '.number_format($r->semanticSimilarity, 4);
            }
            if ($r->keywordScore !== null) {
                $signals .= ', '.__('rag.search.keyword').': '.number_format($r->keywordScore, 4);
            }

            $lines[] = '  ['.($i + 1)."] {$r->title} ({$r->category}){$tags} ({$signals})";
            $lines[] = "      {$r->snippet}";
        }

        return $this->plain(implode("\n", $lines));
    }

    private function plain(string $body): Response
    {
        return response($body, 200)->header('Content-Type', 'text/plain');
    }
}
