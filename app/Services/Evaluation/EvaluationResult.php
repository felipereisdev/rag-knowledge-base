<?php

namespace App\Services\Evaluation;

final readonly class EvaluationResult
{
    /**
     * @param  list<string>  $expectedTitles
     * @param  list<string>  $rankedTitles
     */
    public function __construct(
        public string $query,
        public array $expectedTitles,
        public array $rankedTitles,
        public float $recallAtK,
        public float $reciprocalRank,
        public float $ndcgAtK,
        public bool $zeroResults,
    ) {}
}
