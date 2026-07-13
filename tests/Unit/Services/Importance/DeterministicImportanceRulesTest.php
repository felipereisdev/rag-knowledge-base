<?php

use App\Services\Importance\DeterministicImportanceRules;
use App\Services\Importance\ImportanceCandidate;
use App\Services\Importance\ImportanceCandidateNormalizer;
use App\Services\Importance\NormalizedImportanceCandidate;
use App\Services\Importance\RuleEvaluation;

/**
 * @param  list<array{name:string, type?:string}>  $entities
 */
function ruleCandidate(string $content, string $title = 'Note', array $entities = []): NormalizedImportanceCandidate
{
    return (new ImportanceCandidateNormalizer)->normalize(new ImportanceCandidate(
        title: $title,
        content: $content,
        category: 'insight',
        source: 'condense',
        entities: $entities,
    ));
}

function evaluateRules(string $content, string $title = 'Note'): RuleEvaluation
{
    return (new DeterministicImportanceRules)->evaluate(ruleCandidate($content, $title));
}

/**
 * @return list<string>
 */
function triggeredRuleIds(RuleEvaluation $evaluation): array
{
    return array_map(
        static fn (array $rule): string => $rule['id'],
        $evaluation->triggeredRules,
    );
}

/**
 * The reviewed corpus of knowledge that must never be lost to a rule.
 *
 * @return list<array{id:string, kind:string, retention_reason:string, candidate:array{title:string, content:string, category:string, source:string, tags:list<string>, entities:list<array{name:string, type:string}>, relations:list<array{subject:string, predicate:string, object:string}>}}>
 */
function mustKeepFixtures(): array
{
    $path = base_path('tests/Fixtures/importance/must-keep.json');
    $corpus = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    return $corpus['fixtures'];
}

/**
 * A concrete, decision-bearing baseline that triggers no penalty and no veto.
 */
function baselineContent(): string
{
    return 'We decided that OutboxWriter::append() is the only way to publish a domain event, because publishing inline from the service rolled back a charged order when the broker timed out.';
}

it('hard-vetoes empty content', function () {
    $evaluation = evaluateRules('');

    expect($evaluation->vetoed)->toBeTrue()
        ->and(triggeredRuleIds($evaluation))->toContain('empty_content')
        ->and($evaluation->apply(100))->toBe(0);
});

it('hard-vetoes content that carries no words at all', function () {
    $evaluation = evaluateRules('--- ... !!! ### --- ... !!! ###');

    expect($evaluation->vetoed)->toBeTrue()
        ->and(triggeredRuleIds($evaluation))->toContain('empty_content');
});

it('hard-vetoes placeholder-only content', function () {
    $evaluation = evaluateRules('TODO. TBD. N/A. Placeholder.');

    expect($evaluation->vetoed)->toBeTrue()
        ->and(triggeredRuleIds($evaluation))->toContain('placeholder_only');
});

it('does not veto a placeholder word used inside a real statement', function () {
    $evaluation = evaluateRules('The TODO marker in RetryPolicy must be resolved before release, because the retry limit is still hardcoded to 1 and a transient 503 loses the payment webhook.');

    expect($evaluation->vetoed)->toBeFalse();
});

it('hard-vetoes an unanswered question', function () {
    $evaluation = evaluateRules('How should we handle retries on the shipping API? Should the worker back off exponentially?');

    expect($evaluation->vetoed)->toBeTrue()
        ->and(triggeredRuleIds($evaluation))->toContain('unanswered_question');
});

it('does not veto a question that is followed by its answer', function () {
    $evaluation = evaluateRules('Why is the worker limited to a concurrency of 4? Because the shipping API rejects everything above 60 requests per minute with a 10-minute cooldown.');

    expect($evaluation->vetoed)->toBeFalse();
});

it('hard-vetoes an agent-operation message with no knowledge assertion', function () {
    $evaluation = evaluateRules('Let me read the RetryPolicy file and run the test suite for the shipping module now.');

    expect($evaluation->vetoed)->toBeTrue()
        ->and(triggeredRuleIds($evaluation))->toContain('agent_operation_only');
});

it('does not veto an agent-operation message that carries a knowledge assertion', function () {
    $evaluation = evaluateRules('I have updated RetryPolicy to stop after 3 attempts, because the shipping API applies a 10-minute cooldown after the fourth 429 and the backlog never drains.');

    expect($evaluation->vetoed)->toBeFalse()
        ->and(triggeredRuleIds($evaluation))->not->toContain('agent_operation_only');
});

it('rewards an explicit decision', function () {
    $evaluation = evaluateRules('We decided to serve reporting endpoints from the report_daily_totals projection instead of the write model, since one dashboard request issued 400 queries.');

    expect(triggeredRuleIds($evaluation))->toContain('explicit_decision')
        ->and($evaluation->adjustment)->toBeGreaterThan(0);
});

it('rewards a normative restriction', function () {
    $evaluation = evaluateRules('Production migrations must set lock_timeout to 3s before altering the orders table; never raise the timeout to make a migration succeed.');

    expect(triggeredRuleIds($evaluation))->toContain('normative_restriction');
});

it('rewards a causal rationale', function () {
    $evaluation = evaluateRules('Workers start with --memory=256 because the container limit is 512Mi and an OOM-killed worker drops the job it had reserved.');

    expect(triggeredRuleIds($evaluation))->toContain('causal_rationale');
});

it('rewards an actionable consequence', function () {
    $evaluation = evaluateRules('Comparing the sessions.uuid column against a text parameter forces a cast, which breaks the btree index and turns a 3ms lookup into a 900ms sequential scan.');

    expect(triggeredRuleIds($evaluation))->toContain('actionable_consequence');
});

it('applies the exact named adjustment of every positive signal', function () {
    $evaluation = evaluateRules('We decided that the InvoiceNumberAllocator must allocate numbers under a row lock, because max(id) + 1 races under concurrency and results in a duplicate invoice number.');

    $adjustments = [];
    foreach ($evaluation->triggeredRules as $rule) {
        $adjustments[$rule['id']] = $rule['adjustment'];
    }

    expect($adjustments)->toBe([
        'explicit_decision' => DeterministicImportanceRules::EXPLICIT_DECISION_ADJUSTMENT,
        'normative_restriction' => DeterministicImportanceRules::NORMATIVE_RESTRICTION_ADJUSTMENT,
        'causal_rationale' => DeterministicImportanceRules::CAUSAL_RATIONALE_ADJUSTMENT,
        'actionable_consequence' => DeterministicImportanceRules::ACTIONABLE_CONSEQUENCE_ADJUSTMENT,
    ])
        ->and($evaluation->adjustment)->toBe(
            DeterministicImportanceRules::EXPLICIT_DECISION_ADJUSTMENT
            + DeterministicImportanceRules::NORMATIVE_RESTRICTION_ADJUSTMENT
            + DeterministicImportanceRules::CAUSAL_RATIONALE_ADJUSTMENT
            + DeterministicImportanceRules::ACTIONABLE_CONSEQUENCE_ADJUSTMENT,
        );
});

it('penalizes speculative language', function () {
    $evaluation = evaluateRules('The checkout latency spike on the orders table might be caused by the new index, but I am not sure; maybe the vacuum schedule is the real reason.');

    expect(triggeredRuleIds($evaluation))->toContain('speculative_language')
        ->and($evaluation->adjustment)->toBeLessThan(0);
});

it('penalizes generic wording with no concrete context', function () {
    $evaluation = evaluateRules('The team should always write good code and keep the system clean, because quality matters and users deserve a reliable product every single day.');

    expect(triggeredRuleIds($evaluation))->toContain('generic_without_context');
});

it('does not penalize concrete wording as generic', function () {
    $evaluation = evaluateRules(baselineContent());

    expect(triggeredRuleIds($evaluation))->not->toContain('generic_without_context');
});

it('penalizes a clearly transient status report', function () {
    $evaluation = evaluateRules('The nightly DailyTotalsJob is temporarily disabled for now while we investigate the duplicate rows; we will fix it later this week.');

    expect(triggeredRuleIds($evaluation))->toContain('transient_status');
});

it('penalizes insufficient substance', function () {
    $evaluation = evaluateRules('The orders table must be vacuumed.');

    expect(triggeredRuleIds($evaluation))->toContain('insufficient_substance')
        ->and($evaluation->vetoed)->toBeFalse();
});

it('applies the exact named adjustment of every penalty', function () {
    $evaluation = evaluateRules('Maybe things are broken for now.');

    $adjustments = [];
    foreach ($evaluation->triggeredRules as $rule) {
        $adjustments[$rule['id']] = $rule['adjustment'];
    }

    expect($adjustments)->toBe([
        'speculative_language' => DeterministicImportanceRules::SPECULATIVE_LANGUAGE_ADJUSTMENT,
        'generic_without_context' => DeterministicImportanceRules::GENERIC_WITHOUT_CONTEXT_ADJUSTMENT,
        'transient_status' => DeterministicImportanceRules::TRANSIENT_STATUS_ADJUSTMENT,
        'insufficient_substance' => DeterministicImportanceRules::INSUFFICIENT_SUBSTANCE_ADJUSTMENT,
    ]);
});

it('clamps the final score to 0..100', function () {
    $rewarded = evaluateRules(baselineContent());
    $penalized = evaluateRules('Maybe things are broken for now.');

    expect($rewarded->adjustment)->toBeGreaterThan(0)
        ->and($rewarded->apply(100))->toBe(100)
        ->and($rewarded->apply(60))->toBe(60 + $rewarded->adjustment)
        ->and($penalized->adjustment)->toBeLessThan(0)
        ->and($penalized->apply(0))->toBe(0)
        ->and($penalized->apply(10))->toBe(0)
        ->and($penalized->apply(80))->toBe(80 + $penalized->adjustment);
});

it('forces a vetoed candidate to zero whatever the semantic score is', function () {
    $evaluation = evaluateRules('');

    expect($evaluation->apply(100))->toBe(0)
        ->and($evaluation->apply(0))->toBe(0)
        ->and($evaluation->adjustment)->toBe(DeterministicImportanceRules::VETO_ADJUSTMENT);
});

it('stamps the rules version on every evaluation', function () {
    expect(evaluateRules(baselineContent())->rulesVersion)->toBe(DeterministicImportanceRules::VERSION)
        ->and((new DeterministicImportanceRules('v9'))->evaluate(ruleCandidate(baselineContent()))->rulesVersion)->toBe('v9');
});

it('exposes stable identifiers and concise public reasons for every triggered rule', function () {
    $evaluations = [
        evaluateRules(''),
        evaluateRules('TODO. TBD. N/A. Placeholder.'),
        evaluateRules('How should we handle retries on the shipping API? Should the worker back off?'),
        evaluateRules('Let me read the RetryPolicy file and run the test suite for the shipping module now.'),
        evaluateRules(baselineContent()),
        evaluateRules('Maybe things are broken for now.'),
    ];

    foreach ($evaluations as $evaluation) {
        expect($evaluation->triggeredRules)->not->toBeEmpty();

        foreach ($evaluation->triggeredRules as $rule) {
            expect(array_keys($rule))->toBe(['id', 'adjustment', 'reason'])
                ->and($rule['id'])->toMatch('/^[a-z][a-z_]+[a-z]$/')
                ->and($rule['adjustment'])->toBeInt()
                ->and($rule['reason'])->not->toBeEmpty()
                ->and(mb_strlen($rule['reason']))->toBeLessThanOrEqual(120);
        }
    }
});

it('never hard-vetoes a reviewed must-keep candidate', function () {
    $rules = new DeterministicImportanceRules;

    foreach (mustKeepFixtures() as $fixture) {
        $evaluation = $rules->evaluate(ruleCandidate(
            content: $fixture['candidate']['content'],
            title: $fixture['candidate']['title'],
            entities: $fixture['candidate']['entities'],
        ));

        expect($evaluation->vetoed)->toBeFalse(
            "Must-keep fixture [{$fixture['id']}] was hard-vetoed by: ".implode(', ', triggeredRuleIds($evaluation)),
        );
    }
});

it('recognizes a knowledge signal in every reviewed must-keep candidate', function () {
    $rules = new DeterministicImportanceRules;
    $positiveRuleIds = ['explicit_decision', 'normative_restriction', 'causal_rationale', 'actionable_consequence'];

    foreach (mustKeepFixtures() as $fixture) {
        $evaluation = $rules->evaluate(ruleCandidate(
            content: $fixture['candidate']['content'],
            title: $fixture['candidate']['title'],
            entities: $fixture['candidate']['entities'],
        ));

        expect(array_intersect(triggeredRuleIds($evaluation), $positiveRuleIds))->not->toBeEmpty(
            "Must-keep fixture [{$fixture['id']}] triggered no positive signal.",
        );
    }
});

it('covers every knowledge kind the corpus must protect', function () {
    $fixtures = mustKeepFixtures();

    expect(count($fixtures))->toBeGreaterThanOrEqual(20)
        ->and(array_values(array_unique(array_map(static fn (array $fixture): string => $fixture['kind'], $fixtures))))
        ->toEqualCanonicalizing([
            'architectural-decision',
            'business-rule',
            'operational-constraint',
            'convention',
            'non-obvious-fix',
            'decision-with-rationale',
        ]);

    foreach ($fixtures as $fixture) {
        expect(trim($fixture['retention_reason']))->not->toBeEmpty()
            ->and(trim($fixture['candidate']['content']))->not->toBeEmpty()
            ->and(trim($fixture['candidate']['title']))->not->toBeEmpty();
    }
});
