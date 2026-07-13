<?php

namespace App\Services\Importance;

/**
 * Produces a semantic assessment for a normalized candidate.
 *
 * `ClaudeImportanceJudge` is the production implementation. Task 4's
 * `HybridImportanceClassifier` and later feature tests depend on this
 * interface only, so they can inject a fake in tests without ever invoking
 * the real Claude process.
 */
interface SemanticImportanceJudge
{
    /**
     * @throws ImportanceClassificationException when the process fails or its
     *                                           response does not match the strict contract.
     */
    public function assess(NormalizedImportanceCandidate $candidate): SemanticImportanceAssessment;
}
