<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\Search\HybridSearcher;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RagSearchCommand extends Command
{
    protected $signature = 'rag:search
        {query : The search query}
        {--project= : Project ID (defaults to slugified cwd)}
        {--limit=5 : Maximum number of results}
        {--min-score=0.30 : Minimum similarity score}
        {--category= : Filter by category}
        {--no-graph : Disable knowledge graph expansion}';

    protected $description = 'Search the RAG knowledge base (hybrid vector + FTS + KAG).';

    public function handle(HybridSearcher $searcher): int
    {
        $pid = $this->resolveProjectId();
        $project = Project::find($pid);

        if (! $project) {
            $this->warn("Project '{$pid}' not found. Store knowledge first.");

            return self::SUCCESS;
        }

        $query = (string) $this->argument('query');
        $limit = (int) $this->option('limit');
        $minScore = (float) $this->option('min-score');
        $category = $this->option('category') !== null ? (string) $this->option('category') : null;
        $expandGraph = ! $this->option('no-graph');

        $instance = new HybridSearcher(
            limit: $limit,
            minScore: $minScore,
            expandGraph: $expandGraph,
        );

        $results = $instance->search($query, $pid, $category);

        if ($results === []) {
            $this->info("No results for '{$query}' in '{$project->name}'.");

            return self::SUCCESS;
        }

        $rows = array_map(fn ($r) => [
            'title' => $r->title,
            'category' => $r->category,
            'score' => number_format($r->score, 4),
            'matched_by' => implode('|', $r->matchedBy),
            'id' => $r->entryId,
        ], $results);

        $this->info("Search: '{$query}' in '{$project->name}'");
        $this->newLine();
        $this->table(['Title', 'Category', 'Score', 'Matched By', 'ID'], $rows);

        return self::SUCCESS;
    }

    private function resolveProjectId(): string
    {
        $pid = $this->option('project');
        if ($pid !== null && $pid !== '') {
            return (string) $pid;
        }

        $cwd = (string) getcwd();

        return Str::slug(basename($cwd)) ?: 'project';
    }
}
