<?php

use App\Enums\ImportanceVerdict;
use App\Services\Importance\AutoApprovalPolicy;
use App\Services\Importance\ImportanceClassificationResult;

/**
 * @param  list<array{id:string, adjustment:int, reason:string}>  $rules
 */
function autoApprovalResult(?int $finalScore, array $rules, ?ImportanceVerdict $verdict = ImportanceVerdict::Important): ImportanceClassificationResult
{
    return new ImportanceClassificationResult(
        semanticScore: $finalScore,
        finalScore: $finalScore,
        verdict: $verdict,
        reasons: [],
        triggeredRules: $rules,
        cacheHit: false,
        model: 'claude-haiku-4-5-20251001',
        promptVersion: 'v1',
        rulesVersion: 'v6',
    );
}

function positiveRule(string $id = 'normative_restriction', int $adjustment = 6): array
{
    return ['id' => $id, 'adjustment' => $adjustment, 'reason' => 'States a rule.'];
}

function penaltyRule(string $id = 'speculative_language', int $adjustment = -8): array
{
    return ['id' => $id, 'adjustment' => $adjustment, 'reason' => 'Speculative.'];
}

it('approves a high score carrying a positive signal and no penalty', function () {
    $policy = new AutoApprovalPolicy;

    expect($policy->isEligible(autoApprovalResult(91, [positiveRule()]), 90))->toBeTrue();
});

it('is disabled when the threshold is null', function () {
    $policy = new AutoApprovalPolicy;

    expect($policy->isEligible(autoApprovalResult(100, [positiveRule()]), null))->toBeFalse();
});

it('refuses a score below the auto-approve threshold', function () {
    $policy = new AutoApprovalPolicy;

    expect($policy->isEligible(autoApprovalResult(89, [positiveRule()]), 90))->toBeFalse();
});

it('approves exactly at the threshold', function () {
    $policy = new AutoApprovalPolicy;

    expect($policy->isEligible(autoApprovalResult(90, [positiveRule()]), 90))->toBeTrue();
});

it('refuses a high score with no positive deterministic signal', function () {
    $policy = new AutoApprovalPolicy;

    expect($policy->isEligible(autoApprovalResult(100, []), 90))->toBeFalse();
})->note('This is the injection barrier: the model alone cannot carry an entry into the base.');

it('refuses a high score carrying any penalty, even alongside a positive signal', function () {
    $policy = new AutoApprovalPolicy;

    expect($policy->isEligible(autoApprovalResult(95, [positiveRule(), penaltyRule()]), 90))->toBeFalse();
});

it('refuses a vetoed evaluation', function () {
    $policy = new AutoApprovalPolicy;

    // The score is deliberately ABOVE the threshold, and the verdict deliberately
    // `important`, so the two earlier branches of `isEligible()` both wave this
    // result through and the veto rule is the only thing left that can refuse it.
    // A vetoed evaluation really scores 0, which is why the previous version of
    // this test (finalScore: 0) never reached the loop at all: it was refused by
    // the threshold comparison and asserted nothing whatsoever about the veto.
    $vetoed = autoApprovalResult(95, [
        positiveRule(),
        ['id' => 'agent_operation_only', 'adjustment' => -100, 'reason' => 'Reports an agent operation.'],
    ]);

    expect($policy->isEligible($vetoed, 90))->toBeFalse();
})->note('A veto is just a very large penalty, and it must be refused as one — not incidentally by the score.');

it('refuses a failed classification with no score', function () {
    $policy = new AutoApprovalPolicy;

    expect($policy->isEligible(autoApprovalResult(null, [positiveRule()], null), 90))->toBeFalse();
})->note('Fail-open: a technical failure must never approve.');
