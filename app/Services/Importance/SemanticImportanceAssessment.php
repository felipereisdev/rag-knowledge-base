<?php

namespace App\Services\Importance;

use App\Enums\ImportanceVerdict;

/**
 * The validated, strictly-parsed output of a single semantic judgement.
 *
 * `semanticScore` is always the sum of the five criterion scores computed in
 * PHP (see `ImportanceResponseParser`) — it is never trusted from the model,
 * which does not even receive a total field in its response contract.
 * `recommendedVerdict` is Claude's opinion only; the deterministic layer
 * (Task 4) computes the authoritative verdict from `semanticScore`, rule
 * adjustments, and the configured threshold.
 */
final readonly class SemanticImportanceAssessment
{
    /**
     * @param  list<array{criterion:string, explanation:string}>  $reasons
     */
    public function __construct(
        public int $durability,
        public int $actionability,
        public int $specificity,
        public int $nonObviousness,
        public int $futureValue,
        public int $semanticScore,
        public ImportanceVerdict $recommendedVerdict,
        public array $reasons,
    ) {}
}
