<?php

namespace App\Mcp\Tools;

use App\Exceptions\ProjectNotIdentifiedException;
use App\Mcp\Tools\Concerns\ResolvesProjectId;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('rag_status')]
#[Description('Show the status of the knowledge base for a project: entry counts, tags, categories. Auto-creates the project from the current working directory if project_id is omitted.')]
class RagStatusTool extends Tool
{
    use ResolvesProjectId;

    public function handle(Request $request): Response
    {
        $explicitId = $request->get('project_id');

        // When an explicit project_id is given, look it up directly and
        // report "not found" if missing — rag_status is read-only and must
        // not silently create projects the caller named explicitly. When
        // project_id is omitted, auto-create from the current working
        // directory (the cwd-based flow).
        if ($explicitId !== null && $explicitId !== '') {
            $pid = $explicitId;
        } else {
            try {
                $pid = $this->ensureProject(null, $request->get('cwd'));
            } catch (ProjectNotIdentifiedException $e) {
                return Response::text($e->getMessage());
            }
        }

        $project = Project::find($pid);

        if (! $project) {
            return Response::text("Project '{$pid}' not found.");
        }

        $total = KnowledgeEntry::where('project_id', $pid)->count();
        $approved = KnowledgeEntry::where('project_id', $pid)->where('status', 'approved')->count();
        $pending = KnowledgeEntry::where('project_id', $pid)->where('status', 'pending')->count();
        $rejected = KnowledgeEntry::where('project_id', $pid)->where('status', 'rejected')->count();
        $chunks = DB::table('chunk_embeddings')->where('project_id', $pid)->count();
        $pendingIndexJobs = DB::table('jobs')
            ->where('queue', 'indexing')
            ->count();
        $failedIndexJobs = DB::table('failed_jobs')
            ->where('queue', 'indexing')
            ->count();
        $approvedWithoutChunks = KnowledgeEntry::query()
            ->where('project_id', $pid)
            ->where('status', 'approved')
            ->where('content', '<>', '')
            ->whereDoesntHave('chunks')
            ->count();

        $categoryCounts = KnowledgeEntry::where('project_id', $pid)
            ->select('category', DB::raw('count(*) as count'))
            ->groupBy('category')
            ->pluck('count', 'category')
            ->all();

        $tags = DB::table('tags')->where('project_id', $pid)->pluck('name')->all();

        $lines = [
            "Project: {$project->name} ({$project->id})",
            "  Root: {$project->root_path}",
            '  Description: '.($project->description ?: '(none)'),
            "  Language: {$project->language}",
            '',
            "  Total: {$total} | Approved: {$approved} | Pending: {$pending} | Rejected: {$rejected}",
            "  Chunks: {$chunks}",
            '  '.__('rag.health.index_queue', [
                'pending' => $pendingIndexJobs,
                'failed' => $failedIndexJobs,
                'missing' => $approvedWithoutChunks,
            ]),
        ];

        if ($categoryCounts) {
            $lines[] = '';
            $lines[] = '  By category:';
            foreach ($categoryCounts as $cat => $count) {
                $lines[] = "    {$cat}: {$count}";
            }
        }

        if ($tags) {
            $lines[] = '';
            $lines[] = '  Tags: '.implode(', ', $tags);
        }

        return Response::text(implode("\n", $lines));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()
                ->description('The project ID. If omitted, resolves from the current working directory.'),
        ];
    }
}
