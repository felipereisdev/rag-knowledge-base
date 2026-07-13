<?php

namespace App\Console\Commands;

use App\Services\Evaluation\EvaluationCase;
use App\Services\Evaluation\EvaluationResult;
use App\Services\Evaluation\RetrievalEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
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
            $k = $this->parseKOption();
            $minimumRecall = $this->parseThresholdOption('min-recall');
            $minimumMrr = $this->parseThresholdOption('min-mrr');
            [$projectId, $cases] = $this->loadDataset((string) $this->argument('dataset'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

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

        if ($averageRecall < $minimumRecall || $meanReciprocalRank < $minimumMrr) {
            $this->error(__('rag.evaluation.thresholds_failed'));

            return self::FAILURE;
        }

        $this->info(__('rag.evaluation.thresholds_passed'));

        return self::SUCCESS;
    }

    private function parseKOption(): int
    {
        $rawK = (string) $this->option('k');
        if (preg_match('/^[1-9]\d*$/D', $rawK) !== 1) {
            throw new InvalidArgumentException(__('rag.evaluation.k_option_integer'));
        }

        $k = (int) $rawK;
        $searchLimit = (int) config('rag.search.limit', 10);
        if ($k > $searchLimit) {
            throw new InvalidArgumentException(__('rag.evaluation.k_exceeds_search_limit', [
                'max' => $searchLimit,
            ]));
        }

        return $k;
    }

    private function parseThresholdOption(string $option): float
    {
        $rawValue = (string) $this->option($option);
        if ($rawValue !== trim($rawValue) || ! is_numeric($rawValue)) {
            throw new InvalidArgumentException(__('rag.evaluation.threshold_option_invalid', [
                'option' => "--{$option}",
            ]));
        }

        $value = (float) $rawValue;
        if (! is_finite($value) || $value < 0.0 || $value > 1.0) {
            throw new InvalidArgumentException(__('rag.evaluation.threshold_option_invalid', [
                'option' => "--{$option}",
            ]));
        }

        return $value;
    }

    /**
     * @return array{0: string, 1: list<EvaluationCase>}
     */
    private function loadDataset(string $dataset): array
    {
        $path = str_starts_with($dataset, DIRECTORY_SEPARATOR)
            ? $dataset
            : base_path($dataset);

        if (! File::isFile($path)) {
            throw new RuntimeException(__('rag.evaluation.dataset_not_found', ['path' => $path]));
        }

        try {
            $decoded = json_decode(File::get($path), flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                __('rag.evaluation.dataset_json_invalid'),
                previous: $exception,
            );
        }

        if (! is_object($decoded)) {
            throw new RuntimeException(__('rag.evaluation.dataset_object'));
        }

        $data = get_object_vars($decoded);
        if (! array_key_exists('project_id', $data)) {
            throw new RuntimeException(__('rag.evaluation.project_required'));
        }

        if (! is_string($data['project_id'])) {
            throw new RuntimeException(__('rag.evaluation.project_string'));
        }

        $projectId = trim($data['project_id']);
        if ($projectId === '') {
            throw new RuntimeException(__('rag.evaluation.project_required'));
        }

        $queries = $data['queries'] ?? null;
        if ($queries === []) {
            throw new RuntimeException(__('rag.evaluation.queries_required'));
        }

        if (! is_array($queries) || ! array_is_list($queries)) {
            throw new RuntimeException(__('rag.evaluation.queries_list'));
        }

        $cases = array_map(
            function (mixed $query): EvaluationCase {
                if (! is_object($query)) {
                    throw new RuntimeException(__('rag.evaluation.query_item_object'));
                }

                return EvaluationCase::fromArray(get_object_vars($query));
            },
            $queries,
        );

        return [$projectId, $cases];
    }
}
