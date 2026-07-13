<?php

namespace App\Services\Evaluation;

use App\Services\Search\HybridSearcher;
use App\Services\Search\SearchResult;

final class RetrievalEvaluator
{
    public function __construct(
        private readonly HybridSearcher $searcher,
        private readonly RetrievalMetrics $metrics,
    ) {}

    /**
     * @param  list<EvaluationCase>  $cases
     * @return list<EvaluationResult>
     */
    public function evaluate(string $projectId, array $cases, int $k): array
    {
        $evaluated = [];

        foreach ($cases as $case) {
            $results = $this->searcher->search($case->query, $projectId);
            $rankedTitles = array_map(
                fn (SearchResult $result): string => $result->title,
                array_slice($results, 0, $k),
            );
            $metrics = $this->metrics->calculate(
                rankedTitles: $rankedTitles,
                expectedTitles: $case->expectedTitles,
                k: $k,
            );

            $evaluated[] = new EvaluationResult(
                query: $case->query,
                expectedTitles: $case->expectedTitles,
                rankedTitles: $rankedTitles,
                recallAtK: $metrics['recall'],
                reciprocalRank: $metrics['reciprocalRank'],
                ndcgAtK: $metrics['ndcg'],
                zeroResults: $metrics['zeroResults'],
            );
        }

        return $evaluated;
    }
}
