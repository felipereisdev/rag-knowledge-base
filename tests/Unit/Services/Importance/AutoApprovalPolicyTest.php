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

    $vetoed = autoApprovalResult(0, [['id' => 'empty_content', 'adjustment' => -100, 'reason' => 'Empty.']], ImportanceVerdict::NotImportant);

    expect($policy->isEligible($vetoed, 90))->toBeFalse();
});

it('refuses a failed classification with no score', function () {
    $policy = new AutoApprovalPolicy;

    expect($policy->isEligible(autoApprovalResult(null, [positiveRule()], null), 90))->toBeFalse();
})->note('Fail-open: a technical failure must never approve.');
