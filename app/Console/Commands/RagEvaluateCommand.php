<?php

namespace App\Console\Commands;

use App\Services\Evaluation\EvaluationCase;
use App\Services\Evaluation\EvaluationResult;
use App\Services\Evaluation\RetrievalEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;
use Throwable;

class RagEvaluateCommand extends Command
{
    protected $signature = 'rag:evaluate
        {dataset=resources/evaluations/rag.json}
        {--k=5}
        {--min-recall=0.00}
        {--min-mrr=0.00}';

    public function __construct()
    {
        parent::__construct();
        $this->setDescription(__('rag.evaluation.description'));
    }

    public function handle(RetrievalEvaluator $evaluator): int
    {
        try {
            [$projectId, $cases] = $this->loadDataset((string) $this->argument('dataset'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $k = (int) $this->option('k');
        if ($k < 1) {
            $this->error(__('rag.evaluation.k_option_invalid'));

            return self::FAILURE;
        }

        $results = $evaluator->evaluate($projectId, $cases, $k);

        $rows = array_map(fn (EvaluationResult $result): array => [
            'query' => $result->query,
            'recall' => number_format($result->recallAtK, 4),
            'rr' => number_format($result->reciprocalRank, 4),
            'ndcg' => number_format($result->ndcgAtK, 4),
            'top' => $result->rankedTitles[0] ?? '—',
        ], $results);

        $this->table([
            __('rag.evaluation.query'),
            "Recall@{$k}",
            __('rag.evaluation.reciprocal_rank'),
            "nDCG@{$k}",
            __('rag.evaluation.top_result'),
        ], $rows);

        $count = count($results);
        $averageRecall = array_sum(array_map(
            fn (EvaluationResult $result): float => $result->recallAtK,
            $results,
        )) / $count;
        $meanReciprocalRank = array_sum(array_map(
            fn (EvaluationResult $result): float => $result->reciprocalRank,
            $results,
        )) / $count;
        $averageNdcg = array_sum(array_map(
            fn (EvaluationResult $result): float => $result->ndcgAtK,
            $results,
        )) / $count;
        $zeroResults = count(array_filter(
            $results,
            fn (EvaluationResult $result): bool => $result->zeroResults,
        ));

        $this->line(__('rag.evaluation.average_recall', [
            'k' => $k,
            'value' => number_format($averageRecall, 4),
        ]));
        $this->line(__('rag.evaluation.mean_reciprocal_rank', [
            'value' => number_format($meanReciprocalRank, 4),
        ]));
        $this->line(__('rag.evaluation.average_ndcg', [
            'k' => $k,
            'value' => number_format($averageNdcg, 4),
        ]));
        $this->line(__('rag.evaluation.zero_results', [
            'zero' => $zeroResults,
            'total' => $count,
        ]));

        $minimumRecall = (float) $this->option('min-recall');
        $minimumMrr = (float) $this->option('min-mrr');

        if ($averageRecall < $minimumRecall || $meanReciprocalRank < $minimumMrr) {
            $this->error(__('rag.evaluation.thresholds_failed'));

            return self::FAILURE;
        }

        $this->info(__('rag.evaluation.thresholds_passed'));

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: list<EvaluationCase>}
     *
     * @throws JsonException
     */
    private function loadDataset(string $dataset): array
    {
        $path = str_starts_with($dataset, DIRECTORY_SEPARATOR)
            ? $dataset
            : base_path($dataset);

        if (! File::isFile($path)) {
            throw new RuntimeException(__('rag.evaluation.dataset_not_found', ['path' => $path]));
        }

        $decoded = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException(__('rag.evaluation.dataset_object'));
        }

        $projectId = trim((string) ($decoded['project_id'] ?? ''));
        if ($projectId === '') {
            throw new RuntimeException(__('rag.evaluation.project_required'));
        }

        $queries = $decoded['queries'] ?? null;
        if (! is_array($queries) || $queries === []) {
            throw new RuntimeException(__('rag.evaluation.queries_required'));
        }

        $cases = array_map(
            fn (mixed $query): EvaluationCase => EvaluationCase::fromArray(
                is_array($query) ? $query : [],
            ),
            array_values($queries),
        );

        return [$projectId, $cases];
    }
}
