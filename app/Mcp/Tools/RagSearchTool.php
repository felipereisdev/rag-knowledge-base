<?php

namespace App\Mcp\Tools;

use App\Exceptions\ProjectNotIdentifiedException;
use App\Mcp\Tools\Concerns\ResolvesProjectId;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Services\Search\HybridSearcher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('rag_search')]
#[Description('Search the knowledge base for relevant entries. Use this before answering questions about business rules, architecture, design decisions, or any project knowledge. Returns matching entries with relevance scores. Also expands results through the knowledge graph by default.')]
class RagSearchTool extends Tool
{
    use ResolvesProjectId;

    public function handle(Request $request): Response
    {
        try {
            $pid = $this->resolveProjectId($request->get('project_id'), $request->get('cwd'));
        } catch (ProjectNotIdentifiedException $e) {
            return Response::text($e->getMessage());
        }
        $project = Project::find($pid);

        if (! $project) {
            return Response::text("Project '{$pid}' not found. Store knowledge first.");
        }

        $approvedCount = KnowledgeEntry::where('project_id', $pid)
            ->where('status', 'approved')
            ->count();
        if ($approvedCount === 0) {
            return Response::text("No indexed knowledge in '{$project->name}'. Use rag_store_knowledge and approve entries first.");
        }

        $query = (string) $request->get('query', '');
        $limit = (int) ($request->get('limit') ?? 5);
        $minScore = (float) ($request->get('min_score') ?? 0.30);
        $expandGraph = (bool) ($request->get('expand_graph') ?? true);
        $category = $request->get('category');

        // A fresh searcher is constructed per call so the caller's limit,
        // min_score, and expand_graph overrides actually take effect. The
        // container can't inject a pre-configured instance because the
        // parameters are request-specific.
        $searcher = new HybridSearcher(
            limit: $limit,
            minScore: $minScore,
            expandGraph: $expandGraph,
        );

        $results = $searcher->search($query, $pid, $category);

        if ($results === []) {
            return Response::text("No results for '{$query}' in '{$project->name}'.");
        }

        $lines = ["Search: '{$query}' in '{$project->name}' ({$approvedCount} entries)", ''];

        foreach ($results as $i => $r) {
            $num = $i + 1;
            $tagsStr = $r->tags !== [] ? ' ['.implode(', ', $r->tags).']' : '';
            $matchedStr = ' ['.implode('|', $r->matchedBy).']';
            $lines[] = "  [{$num}] {$r->title} ({$r->category}){$tagsStr}{$matchedStr} (score: {$r->score})";
            $lines[] = "      {$r->snippet}";
            $lines[] = '';
        }

        return Response::text(implode("\n", $lines));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('The search query.')
                ->required(),
            'project_id' => $schema->string()
                ->description('The project ID. If omitted, resolves from the current working directory.'),
            'limit' => $schema->integer()
                ->description('Maximum number of results to return.')
                ->default(5),
            'min_score' => $schema->number()
                ->description('Minimum similarity score (0.0 to 1.0).')
                ->default(0.30),
            'expand_graph' => $schema->boolean()
                ->description('Whether to expand results via the knowledge graph.')
                ->default(true),
            'category' => $schema->string()
                ->description('Filter by category.'),
        ];
    }
}
