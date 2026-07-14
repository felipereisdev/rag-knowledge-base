<?php

namespace App\Mcp\Tools;

use App\Exceptions\ProjectNotIdentifiedException;
use App\Mcp\Tools\Concerns\ResolvesProjectId;
use App\Models\ImportanceClassifierSetting;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Services\Importance\ImportanceStatistics;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('rag_status')]
#[Description('Show the status of the knowledge base for a project: entry counts, tags, categories, and the health of the importance classifier. Auto-creates the project from the current working directory if project_id is omitted.')]
class RagStatusTool extends Tool
{
    use ResolvesProjectId;

    public function handle(Request $request, ImportanceStatistics $statistics): Response|ResponseFactory
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

        $classifier = $this->classifier($pid, $statistics);

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
            '',
            ...$this->classifierLines($classifier),
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

        // The text block stays the contract for the agent reading this tool; the
        // structured payload mirrors it for anything that wants the numbers
        // without parsing prose. Every pre-existing key is kept as it was, and
        // the classifier health is nested under its own object.
        return Response::make(Response::text(implode("\n", $lines)))->withStructuredContent([
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'root_path' => $project->root_path,
                'description' => $project->description,
                'language' => $project->language,
            ],
            'total' => $total,
            'approved' => $approved,
            'pending' => $pending,
            'rejected' => $rejected,
            'chunks' => $chunks,
            'index_queue' => [
                'pending' => $pendingIndexJobs,
                'failed' => $failedIndexJobs,
                'approved_without_chunks' => $approvedWithoutChunks,
            ],
            'categories' => $categoryCounts,
            'tags' => $tags,
            'importance_classifier' => $classifier,
        ]);
    }

    /**
     * The classifier's operational state: what it is configured to do, what it is
     * doing right now, and what it would have done in shadow.
     *
     * @return array{
     *     mode: string,
     *     threshold: int,
     *     model: string,
     *     prompt_version: string,
     *     rules_version: string,
     *     classifying: int,
     *     stale_classifying: int,
     *     stale_after_minutes: int,
     *     assessments: array{succeeded: int, failed: int},
     *     shadow: array{would_keep: int, would_reject: int},
     *     queue: array{name: string, pending: int|null, failed: int|null},
     * }
     */
    private function classifier(string $projectId, ImportanceStatistics $statistics): array
    {
        $setting = ImportanceClassifierSetting::current();
        $classifying = $statistics->classifying($projectId);

        return [
            'mode' => $setting->mode->value,
            'threshold' => $setting->threshold,
            'model' => (string) config('rag.importance.model'),
            'prompt_version' => (string) config('rag.importance.prompt_version'),
            'rules_version' => (string) config('rag.importance.rules_version'),
            'classifying' => $classifying['total'],
            'stale_classifying' => $classifying['stale'],
            'stale_after_minutes' => $classifying['stale_after_minutes'],
            'assessments' => $statistics->assessments($projectId),
            // Shadow only: `would_reject` is written under `enforce` too, and an
            // enforce rejection is not evidence of what shadow would have done.
            'shadow' => $statistics->shadowVerdicts($projectId),
            'queue' => $statistics->queue(),
        ];
    }

    /**
     * @param  array<string, mixed>  $classifier
     * @return list<string>
     */
    private function classifierLines(array $classifier): array
    {
        /** @var array{name: string, pending: int|null, failed: int|null} $queue */
        $queue = $classifier['queue'];

        /** @var array{succeeded: int, failed: int} $assessments */
        $assessments = $classifier['assessments'];

        /** @var array{would_keep: int, would_reject: int} $shadow */
        $shadow = $classifier['shadow'];

        return [
            '  '.__('importance.status.heading', [
                'mode' => $classifier['mode'],
                'threshold' => $classifier['threshold'],
            ]),
            '    '.__('importance.status.versions', [
                'model' => $classifier['model'],
                'prompt' => $classifier['prompt_version'],
                'rules' => $classifier['rules_version'],
            ]),
            '    '.__('importance.status.classifying', [
                'count' => $classifier['classifying'],
                'minutes' => $classifier['stale_after_minutes'],
                'stale' => $classifier['stale_classifying'],
            ]),
            '    '.__('importance.status.assessments', [
                'succeeded' => $assessments['succeeded'],
                'failed' => $assessments['failed'],
            ]),
            '    '.__('importance.status.shadow', [
                'keep' => $shadow['would_keep'],
                'reject' => $shadow['would_reject'],
            ]),
            '    '.($queue['pending'] === null
                ? __('importance.status.queue_unavailable')
                : __('importance.status.queue', [
                    'pending' => $queue['pending'],
                    'failed' => $queue['failed'],
                ])),
        ];
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
