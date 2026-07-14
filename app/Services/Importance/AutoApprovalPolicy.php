<?php

namespace App\Services\Importance;

/**
 * The single decision point for whether a classified entry may be approved
 * without a human reading it.
 *
 * Approving is not the mirror image of rejecting. An approved entry becomes
 * retrievable by search, so it is served to agents as trusted project
 * knowledge. Candidate text is untrusted, and the semantic score is produced
 * by the very model an injection would target — so a high score alone must
 * never be enough.
 *
 * The deterministic signals are the barrier the model cannot move: they are
 * regex, not inference. To be auto-approved, a candidate must convince Claude
 * AND read as durable knowledge to an automaton.
 *
 * Eligibility is a pure function of the assessment and the rules; it does not
 * depend on the classifier mode. Only `enforce` acts on it — `shadow` records
 * it as `would_approve` and approves nothing.
 */
final class AutoApprovalPolicy
{
    /**
     * @param  int|null  $autoApproveThreshold  null disables auto-approval entirely.
     */
    public function isEligible(ImportanceClassificationResult $result, ?int $autoApproveThreshold): bool
    {
        if ($autoApproveThreshold === null) {
            return false;
        }

        // A failed classification has no score. Fail-open: never approve.
        if ($result->finalScore === null) {
            return false;
        }

        if ($result->finalScore < $autoApproveThreshold) {
            return false;
        }

        $hasPositiveSignal = false;

        foreach ($result->triggeredRules as $rule) {
            // Any penalty (or a veto, which is just a large penalty) disqualifies:
            // if something smelled off enough to cost points, a human looks at it.
            if ($rule['adjustment'] < 0) {
                return false;
            }

            if ($rule['adjustment'] > 0) {
                $hasPositiveSignal = true;
            }
        }

        return $hasPositiveSignal;
    }
}
