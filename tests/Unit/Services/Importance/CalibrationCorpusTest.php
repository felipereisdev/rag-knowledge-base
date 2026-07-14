<?php

use App\Enums\ImportanceVerdict;
use App\Models\ImportanceClassifierSetting;
use App\Models\Project;
use App\Services\Importance\DeterministicImportanceRules;
use App\Services\Importance\HybridImportanceClassifier;
use App\Services\Importance\ImportanceCandidate;
use App\Services\Importance\ImportanceCandidateNormalizer;
use App\Services\Importance\NormalizedImportanceCandidate;
use App\Services\Importance\SemanticImportanceAssessment;
use App\Services\Importance\SemanticImportanceJudge;

/**
 * The semantic half, fixed. The corpus records the score a reviewer decided a
 * judge plausibly returns for each fixture, so the whole calibration suite is
 * reproducible offline and never touches the `claude` binary. It also counts
 * its calls, which is how a veto ("the judge is never consulted") is proved
 * rather than assumed.
 */
final class CorpusFixedJudge implements SemanticImportanceJudge
{
    public int $calls = 0;

    public function __construct(private readonly int $semanticScore) {}

    public function assess(NormalizedImportanceCandidate $candidate): SemanticImportanceAssessment
    {
        $this->calls++;

        // The five criteria are an implementation detail of the judge; the
        // classifier only ever sums them into `semanticScore`, which is what the
        // corpus pins. Spreading the reviewed total across the criteria would
        // invent five numbers nobody reviewed.
        return new SemanticImportanceAssessment(
            durability: 0,
            actionability: 0,
            specificity: 0,
            nonObviousness: 0,
            futureValue: 0,
            semanticScore: $this->semanticScore,
            recommendedVerdict: ImportanceVerdict::NotImportant,
            reasons: [],
        );
    }
}

beforeEach(function () {
    Project::create(['id' => 'calibration', 'name' => 'Calibration', 'root_path' => '/calibration']);
});

/**
 * @return array{_meta: array<string, string>, fixtures: list<array<string, mixed>>}
 */
function calibrationCorpus(string $set): array
{
    $path = resource_path("importance/{$set}.json");

    expect(is_file($path))->toBeTrue("The reviewed calibration corpus [{$set}] is missing from resources/importance.");

    /** @var array{_meta: array<string, string>, fixtures: list<array<string, mixed>>} $corpus */
    $corpus = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    return $corpus;
}

/**
 * @return list<array<string, mixed>>
 */
function calibrationFixtures(string $set): array
{
    return calibrationCorpus($set)['fixtures'];
}

/**
 * @param  array{title:string, content:string, category:string, source:string, tags:list<string>, entities:list<array{name:string, type:string}>, relations:list<array{subject:string, predicate:string, object:string}>}  $candidate
 */
function calibrationCandidate(array $candidate): ImportanceCandidate
{
    return new ImportanceCandidate(
        title: $candidate['title'],
        content: $candidate['content'],
        category: $candidate['category'],
        source: $candidate['source'],
        tags: $candidate['tags'],
        entities: $candidate['entities'],
        relations: $candidate['relations'],
    );
}

/**
 * @param  array{title:string, content:string, category:string, source:string, tags:list<string>, entities:list<array{name:string, type:string}>, relations:list<array{subject:string, predicate:string, object:string}>}  $candidate
 */
function calibrationNormalized(array $candidate): NormalizedImportanceCandidate
{
    return (new ImportanceCandidateNormalizer)->normalize(calibrationCandidate($candidate));
}

/**
 * @return list<string>
 */
function calibrationRuleIds(array $triggeredRules): array
{
    return array_map(static fn (array $rule): string => $rule['id'], $triggeredRules);
}

/**
 * The real classifier with the semantic half fixed. Everything else — the
 * normalizer, the deterministic rules, the assessment cache, the threshold
 * comparison — is production code.
 */
function calibrationClassifier(CorpusFixedJudge $judge): HybridImportanceClassifier
{
    return new HybridImportanceClassifier(
        new ImportanceCandidateNormalizer,
        new DeterministicImportanceRules,
        $judge,
        model: 'test-model',
        promptVersion: 'v1',
    );
}

function calibrationThreshold(int $threshold): void
{
    ImportanceClassifierSetting::query()->findOrFail(1)->update(['threshold' => $threshold]);
}

it('carries at least fifty reviewed examples across the three sets', function () {
    $counts = [
        'must-keep' => count(calibrationFixtures('must-keep')),
        'must-reject' => count(calibrationFixtures('must-reject')),
        'borderline' => count(calibrationFixtures('borderline')),
    ];

    expect($counts['must-keep'])->toBeGreaterThanOrEqual(20)
        ->and($counts['must-reject'])->toBeGreaterThanOrEqual(15)
        ->and($counts['borderline'])->toBeGreaterThanOrEqual(10)
        ->and(array_sum($counts))->toBeGreaterThanOrEqual(50);
});

it('records a reviewer rationale and a reviewed rules version for every calibration example', function () {
    $rationaleKey = [
        'must-keep' => 'retention_reason',
        'must-reject' => 'rejection_reason',
        'borderline' => 'reviewer_note',
    ];

    foreach ($rationaleKey as $set => $key) {
        expect(calibrationCorpus($set)['_meta']['rules_version_reviewed'])->toBe(DeterministicImportanceRules::VERSION,
            "The [{$set}] corpus was reviewed against a different rules version than the one in force. Re-review it, do not just bump the string.",
        );

        foreach (calibrationFixtures($set) as $fixture) {
            expect(trim($fixture['id']))->not->toBeEmpty()
                ->and(trim($fixture['kind']))->not->toBeEmpty()
                ->and(trim((string) ($fixture[$key] ?? '')))->not->toBeEmpty(
                    "Calibration example [{$fixture['id']}] carries no reviewer rationale under [{$key}].",
                )
                ->and(trim($fixture['candidate']['title']))->not->toBeEmpty();
        }
    }
});

it('is varied rather than one example reworded', function () {
    // A corpus of fifty paraphrases of one sentence proves nothing. Each set has
    // to span several distinct kinds, and no single kind may dominate it.
    foreach (['must-keep', 'must-reject', 'borderline'] as $set) {
        $fixtures = calibrationFixtures($set);
        $kinds = array_count_values(array_map(static fn (array $f): string => $f['kind'], $fixtures));

        expect(count($kinds))->toBeGreaterThanOrEqual(5, "The [{$set}] corpus spans too few kinds of example.")
            ->and(max($kinds))->toBeLessThanOrEqual((int) ceil(count($fixtures) * 0.6),
                "One kind dominates the [{$set}] corpus, so it is not really varied.",
            );

        $contents = array_map(static fn (array $f): string => $f['candidate']['content'], $fixtures);

        expect(count(array_unique($contents)))->toBe(count($contents),
            "The [{$set}] corpus repeats a candidate body verbatim.",
        );
    }
});

it('hard-vetoes every reviewed must-reject fixture that the rules must refuse alone', function () {
    $rules = new DeterministicImportanceRules;

    foreach (calibrationFixtures('must-reject') as $fixture) {
        if ($fixture['expected']['disposition'] !== 'veto') {
            continue;
        }

        $evaluation = $rules->evaluate(calibrationNormalized($fixture['candidate']));
        $triggered = calibrationRuleIds($evaluation->triggeredRules);

        expect($evaluation->vetoed)->toBeTrue(
            "Must-reject fixture [{$fixture['id']}] escaped the veto entirely; it triggered: ".implode(', ', $triggered),
        );

        foreach ($fixture['expected']['rules'] as $expectedRule) {
            // The NAMED veto, not merely some veto. A fixture reviewed as agent
            // chatter that is refused by `empty_content` instead means the rule
            // under review is dead and something else is doing its work.
            expect(in_array($expectedRule, $triggered, true))->toBeTrue(
                "Must-reject fixture [{$fixture['id']}] was vetoed, but not by [{$expectedRule}] — it triggered: ".implode(', ', $triggered),
            );
        }
    }
});

it('refuses every vetoed must-reject fixture at every threshold, without ever consulting the judge', function () {
    // A hard veto is not a low score: it must survive an administrator who turns
    // the dial all the way down, and it must cost nothing, because the judge is
    // never asked.
    foreach (calibrationFixtures('must-reject') as $fixture) {
        if ($fixture['expected']['disposition'] !== 'veto') {
            continue;
        }

        foreach ([0, 70] as $threshold) {
            calibrationThreshold($threshold);

            // A perfect semantic score, so the assertion is that the veto beats
            // the judge rather than that the judge happened to agree.
            $judge = new CorpusFixedJudge(100);
            $result = calibrationClassifier($judge)->classify('calibration', calibrationCandidate($fixture['candidate']));

            expect($result->verdict)->toBe(ImportanceVerdict::NotImportant,
                "Must-reject fixture [{$fixture['id']}] was accepted at threshold {$threshold}.",
            )
                ->and($result->finalScore)->toBe(0)
                ->and($judge->calls)->toBe(0,
                    "Must-reject fixture [{$fixture['id']}] paid for a model call even though the rules had already refused it.",
                );
        }
    }
});

it('rejects the must-reject noise the rules deliberately let through, on its score', function () {
    // The veto's accepted recall loss, pinned. These fixtures MUST NOT be vetoed
    // — the grammar cannot tell them apart from real knowledge — so the low
    // semantic score is the whole of what rejects them. If one of them starts
    // being vetoed, a precision regression has been introduced and real knowledge
    // is being destroyed somewhere out of sight.
    $rules = new DeterministicImportanceRules;

    foreach (calibrationFixtures('must-reject') as $fixture) {
        if ($fixture['expected']['disposition'] !== 'score') {
            continue;
        }

        // No veto AT ALL, not merely no `agent_operation_only`. These fixtures are
        // rejected on their score, and `reject-chatter-with-parenthetical` scores 0
        // once clamped — so a veto by ANY other rule would produce the very same
        // final score and verdict and slip through unnoticed. The blanket assertion
        // is what makes the escape observable.
        $evaluation = $rules->evaluate(calibrationNormalized($fixture['candidate']));

        expect($evaluation->vetoed)->toBeFalse(
            "Must-reject fixture [{$fixture['id']}] is a documented ESCAPE and must reach the judge, but it was hard-vetoed by: ".
            implode(', ', calibrationRuleIds($evaluation->triggeredRules)).
            '. A veto here means the grammar was widened and the false-veto class of rounds 1-4 is back.',
        );

        $judged = false;

        foreach ($fixture['expected']['verdict_at'] as $threshold => $expectedVerdict) {
            calibrationThreshold((int) $threshold);

            $judge = new CorpusFixedJudge($fixture['expected']['semantic_score']);
            $result = calibrationClassifier($judge)->classify('calibration', calibrationCandidate($fixture['candidate']));

            expect(in_array('agent_operation_only', calibrationRuleIds($result->triggeredRules), true))->toBeFalse(
                "Must-reject fixture [{$fixture['id']}] triggered [agent_operation_only], the rule it is on record as escaping.",
            )
                // The first threshold pays for the judgement; every later one is
                // the same candidate under the same cache identity and must reuse
                // it, which is also why the escape is cheap.
                ->and($judge->calls)->toBe($judged ? 0 : 1,
                    "Must-reject fixture [{$fixture['id']}] consulted the judge ".$judge->calls.' times.',
                )
                ->and($result->finalScore)->toBe($fixture['expected']['final_score'],
                    "Must-reject fixture [{$fixture['id']}] no longer scores what the reviewer recorded.",
                )
                ->and($result->verdict->value)->toBe($expectedVerdict,
                    "Must-reject fixture [{$fixture['id']}] came out {$result->verdict->value} at threshold {$threshold}.",
                );

            $judged = true;
        }
    }
});

it('never hard-vetoes a borderline candidate', function () {
    // The invariant the whole borderline set exists for. A candidate a human had
    // to think about is by definition arguable, and a hard veto is irreversible,
    // threshold-proof and (under `enforce`) silent. Anything arguable must reach
    // the judge and then a human. A failure here is a defect in the RULE.
    $rules = new DeterministicImportanceRules;

    foreach (calibrationFixtures('borderline') as $fixture) {
        $evaluation = $rules->evaluate(calibrationNormalized($fixture['candidate']));

        expect($evaluation->vetoed)->toBeFalse(
            "Borderline fixture [{$fixture['id']}] was hard-vetoed by: ".implode(', ', calibrationRuleIds($evaluation->triggeredRules)).
            '. A borderline candidate must always reach the judge — fix the RULE, not the fixture.',
        );
    }
});

it('decides every borderline candidate exactly as reviewed, at each threshold', function () {
    foreach (calibrationFixtures('borderline') as $fixture) {
        $judged = false;

        foreach ($fixture['expected']['verdict_at'] as $threshold => $expectedVerdict) {
            calibrationThreshold((int) $threshold);

            $judge = new CorpusFixedJudge($fixture['expected']['semantic_score']);
            $result = calibrationClassifier($judge)->classify('calibration', calibrationCandidate($fixture['candidate']));

            // The semantic score is NOT asserted against the fixture here: the judge
            // was constructed with that very number, so on the first pass it would
            // only assert that the fake returned what the fake was handed. What is
            // worth pinning is what the classifier did WITH it — the deterministic
            // adjustment, the clamp, and the threshold comparison below — and that
            // the cached pass re-derives the same final score from the stored row
            // without a judge call.
            expect($result->finalScore)->toBe($fixture['expected']['final_score'],
                "Borderline fixture [{$fixture['id']}] no longer scores what the reviewer recorded: the rule adjustments moved under it.",
            )
                ->and($result->verdict->value)->toBe($expectedVerdict,
                    "Borderline fixture [{$fixture['id']}] came out {$result->verdict->value} at threshold {$threshold}, not {$expectedVerdict}.",
                )
                // The threshold is deliberately NOT part of the cache identity:
                // the first threshold pays for the judgement and every later one
                // re-derives its verdict from the stored assessment. Moving the
                // dial must never re-run the model over the whole corpus.
                ->and($judge->calls)->toBe($judged ? 0 : 1,
                    "Borderline fixture [{$fixture['id']}] consulted the judge ".$judge->calls.' times at threshold '.$threshold.'; only the threshold moved.',
                )
                ->and($result->cacheHit)->toBe($judged);

            $judged = true;
        }
    }
});

it('moves a borderline candidate across the line when only the threshold moves', function () {
    // The borderline set is not decorative: at least a third of it must actually
    // straddle the band, or the corpus is a second must-keep/must-reject wearing
    // a different name and pins nothing about the dial.
    $straddling = 0;

    foreach (calibrationFixtures('borderline') as $fixture) {
        if (count(array_unique(array_values($fixture['expected']['verdict_at']))) > 1) {
            $straddling++;
        }
    }

    expect($straddling)->toBeGreaterThanOrEqual((int) ceil(count(calibrationFixtures('borderline')) / 3),
        'Too few borderline fixtures actually change verdict as the threshold moves, so the set is not borderline.',
    );
});

it('reproduces the recorded candidate hash of every calibration example', function () {
    // The cache identity. `candidate_hash` is one fifth of the key an assessment
    // is stored under, so a change in the normalizer silently invalidates every
    // stored assessment in production and re-runs the model over the whole
    // corpus. These are golden hashes: if they move, that is the change, and it
    // is a deliberate one or it is a bug.
    foreach (['must-reject', 'borderline'] as $set) {
        foreach (calibrationFixtures($set) as $fixture) {
            expect(calibrationNormalized($fixture['candidate'])->hash())->toBe($fixture['candidate_hash'],
                "The normalized hash of [{$fixture['id']}] changed. Every cached assessment in production is keyed on this.",
            );
        }
    }
});

it('hashes every calibration candidate deterministically and distinctly', function () {
    $hashes = [];

    foreach (['must-keep', 'must-reject', 'borderline'] as $set) {
        foreach (calibrationFixtures($set) as $fixture) {
            $first = calibrationNormalized($fixture['candidate'])->hash();
            $second = calibrationNormalized($fixture['candidate'])->hash();

            expect($second)->toBe($first, "Hashing [{$fixture['id']}] twice produced two different hashes.")
                ->and($first)->toMatch('/^[0-9a-f]{64}$/');

            expect(array_key_exists($first, $hashes))->toBeFalse(
                "Calibration example [{$fixture['id']}] hashes identically to [".($hashes[$first] ?? '?').'], so one of them is a duplicate and the corpus is smaller than it claims.',
            );

            $hashes[$first] = $fixture['id'];
        }
    }
});

it('hashes past formatting but not past meaning', function () {
    $fixture = calibrationFixtures('borderline')[0];
    $candidate = $fixture['candidate'];
    $hash = calibrationNormalized($candidate)->hash();

    // Formatting the same knowledge differently must not cost a model call: CRLF
    // line endings, runs of spaces, trailing whitespace, and the order the caller
    // happened to pass tags and entities in are all noise.
    $reformatted = $candidate;
    $reformatted['content'] = "  \r\n".str_replace(' ', '  ', $candidate['content'])."  \r\n\r\n\r\n";
    $reformatted['tags'] = array_reverse($candidate['tags']);
    $reformatted['entities'] = array_reverse($candidate['entities']);

    expect(calibrationNormalized($reformatted)->hash())->toBe($hash,
        'Re-formatting the same candidate changed its cache identity, so trivial whitespace churn now pays for a fresh model call.',
    );

    // But a single changed character is a different candidate and must be judged
    // afresh, or a corrected entry would keep serving the old verdict forever.
    $edited = $candidate;
    $edited['content'] = $candidate['content'].' It also applies to the sandbox tenant.';

    expect(calibrationNormalized($edited)->hash())->not->toBe($hash,
        'An edited candidate kept its cache identity, so it would reuse the assessment of the text it no longer is.',
    );
});

it('keeps the calibration corpus synthetic', function () {
    // The corpus ships inside the production image. A real credential, hostname
    // or customer name that lands here is published, not merely committed.
    $secretish = [
        '/sk-[a-zA-Z0-9]{16,}/',
        '/(?:ghp|gho|github_pat)_[A-Za-z0-9_]{16,}/',
        '/AKIA[0-9A-Z]{16}/',
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        '/\b[A-Za-z0-9._%+-]+@(?!example\.(?:com|org))[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/',
        '/\bpostgres(?:ql)?:\/\/[^\s]*:[^\s]*@/',
    ];

    foreach (['must-keep', 'must-reject', 'borderline'] as $set) {
        $raw = (string) file_get_contents(resource_path("importance/{$set}.json"));

        foreach ($secretish as $pattern) {
            expect(preg_match($pattern, $raw))->toBe(0,
                "The [{$set}] corpus matches {$pattern}, which looks like real data rather than a synthetic example.",
            );
        }
    }
});
