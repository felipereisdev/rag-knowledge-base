<?php

use App\Enums\ImportanceAssessmentStatus;
use App\Enums\ImportanceVerdict;
use App\Models\ImportanceAssessment;
use App\Models\ImportanceClassifierSetting;
use App\Models\Project;
use App\Services\Importance\DeterministicImportanceRules;
use App\Services\Importance\HybridImportanceClassifier;
use App\Services\Importance\ImportanceAssessmentInProgressException;
use App\Services\Importance\ImportanceCandidate;
use App\Services\Importance\ImportanceCandidateNormalizer;
use App\Services\Importance\ImportanceClassificationException;
use App\Services\Importance\NormalizedImportanceCandidate;
use App\Services\Importance\SemanticImportanceAssessment;
use App\Services\Importance\SemanticImportanceJudge;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Test double for the semantic half: it never launches a process, counts how
 * often it was consulted (so cache hits are provable), and can raise the same
 * typed failures the real Claude judge raises.
 */
final class RecordingJudge implements SemanticImportanceJudge
{
    public int $calls = 0;

    public function __construct(
        private readonly SemanticImportanceAssessment|ImportanceClassificationException $response,
    ) {}

    public function assess(NormalizedImportanceCandidate $candidate): SemanticImportanceAssessment
    {
        $this->calls++;

        if ($this->response instanceof ImportanceClassificationException) {
            throw $this->response;
        }

        return $this->response;
    }
}

beforeEach(function () {
    Project::create(['id' => 'classifier', 'name' => 'Classifier', 'root_path' => '/classifier']);
});

function setImportanceThreshold(int $threshold): void
{
    ImportanceClassifierSetting::query()->findOrFail(1)->update(['threshold' => $threshold]);
}

/**
 * Deliberately unremarkable content: it triggers no rule at all, so a test that
 * cares about scores is not silently reading a rule adjustment.
 */
function neutralCandidate(string $title = 'Session note'): ImportanceCandidate
{
    return new ImportanceCandidate(
        title: $title,
        content: 'The InvoiceNumberAllocator hands out one number per fiscal year and the relay worker ships the row to the broker on the next tick of the loop.',
        category: 'insight',
        source: 'condense',
    );
}

/**
 * Content that triggers exactly two positive signals (+6 decision, +5 rationale).
 */
function rewardedCandidate(): ImportanceCandidate
{
    return new ImportanceCandidate(
        title: 'Outbox',
        content: 'We decided to publish domain events through the OutboxWriter, because an inline broker call rolled back an order that had already been charged.',
        category: 'architecture',
        source: 'condense',
    );
}

function semanticAssessment(
    int $durability = 15,
    int $actionability = 12,
    int $specificity = 12,
    int $nonObviousness = 12,
    int $futureValue = 9,
    ImportanceVerdict $recommended = ImportanceVerdict::Important,
): SemanticImportanceAssessment {
    return new SemanticImportanceAssessment(
        durability: $durability,
        actionability: $actionability,
        specificity: $specificity,
        nonObviousness: $nonObviousness,
        futureValue: $futureValue,
        semanticScore: $durability + $actionability + $specificity + $nonObviousness + $futureValue,
        recommendedVerdict: $recommended,
        reasons: [['criterion' => 'durability', 'explanation' => 'The rule outlives the session.']],
    );
}

/**
 * How long a `running` assessment may go without a write before it is treated as
 * abandoned rather than as live contention.
 */
const STALE_AFTER_MINUTES = 15;

function importanceClassifier(
    SemanticImportanceJudge $judge,
    string $model = 'claude-test',
    string $promptVersion = 'v1',
    ?DeterministicImportanceRules $rules = null,
): HybridImportanceClassifier {
    return new HybridImportanceClassifier(
        new ImportanceCandidateNormalizer,
        $rules ?? new DeterministicImportanceRules,
        $judge,
        model: $model,
        promptVersion: $promptVersion,
        staleAfterMinutes: STALE_AFTER_MINUTES,
    );
}

it('sums the semantic criteria and then applies the deterministic adjustments', function () {
    setImportanceThreshold(70);
    $judge = new RecordingJudge(semanticAssessment());

    $result = importanceClassifier($judge)->classify('classifier', rewardedCandidate());

    $expectedAdjustment = DeterministicImportanceRules::EXPLICIT_DECISION_ADJUSTMENT
        + DeterministicImportanceRules::CAUSAL_RATIONALE_ADJUSTMENT;

    expect($result->semanticScore)->toBe(60)
        ->and($result->finalScore)->toBe(60 + $expectedAdjustment)
        ->and($result->cacheHit)->toBeFalse()
        ->and(array_column($result->triggeredRules, 'id'))
        ->toBe(['explicit_decision', 'causal_rationale'])
        ->and($result->rulesVersion)->toBe(DeterministicImportanceRules::VERSION)
        ->and($result->model)->toBe('claude-test')
        ->and($judge->calls)->toBe(1);

    $assessment = ImportanceAssessment::query()->sole();

    expect($assessment->status)->toBe(ImportanceAssessmentStatus::Succeeded)
        ->and($assessment->semantic_score)->toBe(60)
        ->and($assessment->final_score)->toBe(71)
        ->and($assessment->durability_score)->toBe(15)
        ->and($assessment->future_value_score)->toBe(9)
        // jsonb does not preserve key order, so the round-tripped rows are
        // compared by content, not by identity.
        ->and($assessment->reasons)->toEqual([['criterion' => 'durability', 'explanation' => 'The rule outlives the session.']])
        ->and($assessment->rules)->toEqual($result->triggeredRules)
        ->and($assessment->duration_ms)->toBeGreaterThanOrEqual(0)
        ->and($assessment->error_code)->toBeNull()
        ->and($assessment->normalized_candidate['content'])->toBe(rewardedCandidate()->content);
});

it('is important exactly when the final score reaches the threshold', function () {
    setImportanceThreshold(60);

    $atThreshold = importanceClassifier(new RecordingJudge(semanticAssessment()))
        ->classify('classifier', neutralCandidate('At threshold'));

    expect($atThreshold->finalScore)->toBe(60)
        ->and($atThreshold->verdict)->toBe(ImportanceVerdict::Important);

    setImportanceThreshold(61);

    $belowThreshold = importanceClassifier(new RecordingJudge(semanticAssessment()))
        ->classify('classifier', neutralCandidate('Below threshold'));

    expect($belowThreshold->finalScore)->toBe(60)
        ->and($belowThreshold->verdict)->toBe(ImportanceVerdict::NotImportant);
});

it('records the verdict Claude recommends but never lets it override the computed one', function () {
    setImportanceThreshold(70);

    $optimistic = importanceClassifier(new RecordingJudge(semanticAssessment(recommended: ImportanceVerdict::Important)))
        ->classify('classifier', neutralCandidate('Optimistic'));

    expect($optimistic->recommendedVerdict)->toBe(ImportanceVerdict::Important)
        ->and($optimistic->finalScore)->toBe(60)
        ->and($optimistic->verdict)->toBe(ImportanceVerdict::NotImportant);

    $pessimistic = importanceClassifier(new RecordingJudge(semanticAssessment(
        durability: 25,
        actionability: 20,
        specificity: 20,
        nonObviousness: 20,
        futureValue: 15,
        recommended: ImportanceVerdict::NotImportant,
    )))->classify('classifier', neutralCandidate('Pessimistic'));

    expect($pessimistic->recommendedVerdict)->toBe(ImportanceVerdict::NotImportant)
        ->and($pessimistic->finalScore)->toBe(100)
        ->and($pessimistic->verdict)->toBe(ImportanceVerdict::Important);
});

it('reuses a succeeded assessment instead of consulting the judge again', function () {
    setImportanceThreshold(70);
    $judge = new RecordingJudge(semanticAssessment());
    $candidate = neutralCandidate();

    $first = importanceClassifier($judge)->classify('classifier', $candidate);
    $second = importanceClassifier($judge)->classify('classifier', $candidate);

    expect($judge->calls)->toBe(1)
        ->and($first->cacheHit)->toBeFalse()
        ->and($second->cacheHit)->toBeTrue()
        ->and($second->semanticScore)->toBe($first->semanticScore)
        ->and($second->finalScore)->toBe($first->finalScore)
        ->and($second->verdict)->toBe($first->verdict)
        ->and($second->reasons)->toEqual($first->reasons)
        ->and($second->triggeredRules)->toEqual($first->triggeredRules)
        ->and(ImportanceAssessment::query()->count())->toBe(1);
});

it('recalculates the verdict from the cached assessment when only the threshold changes', function () {
    setImportanceThreshold(70);
    $judge = new RecordingJudge(semanticAssessment());
    $candidate = neutralCandidate();

    $strict = importanceClassifier($judge)->classify('classifier', $candidate);

    setImportanceThreshold(50);

    $relaxed = importanceClassifier($judge)->classify('classifier', $candidate);

    expect($judge->calls)->toBe(1)
        ->and($strict->verdict)->toBe(ImportanceVerdict::NotImportant)
        ->and($relaxed->cacheHit)->toBeTrue()
        ->and($relaxed->semanticScore)->toBe(60)
        ->and($relaxed->finalScore)->toBe(60)
        ->and($relaxed->verdict)->toBe(ImportanceVerdict::Important)
        ->and(ImportanceAssessment::query()->count())->toBe(1)
        ->and(ImportanceAssessment::query()->sole()->verdict)->toBe(ImportanceVerdict::Important);
});

it('misses the cache when the candidate changes', function () {
    setImportanceThreshold(70);
    $judge = new RecordingJudge(semanticAssessment());

    importanceClassifier($judge)->classify('classifier', neutralCandidate('First title'));
    $second = importanceClassifier($judge)->classify('classifier', neutralCandidate('Second title'));

    expect($judge->calls)->toBe(2)
        ->and($second->cacheHit)->toBeFalse()
        ->and(ImportanceAssessment::query()->count())->toBe(2);
});

it('misses the cache when the project changes', function () {
    setImportanceThreshold(70);
    Project::create(['id' => 'other', 'name' => 'Other', 'root_path' => '/other']);
    $judge = new RecordingJudge(semanticAssessment());
    $candidate = neutralCandidate();

    importanceClassifier($judge)->classify('classifier', $candidate);
    importanceClassifier($judge)->classify('other', $candidate);

    expect($judge->calls)->toBe(2)
        ->and(ImportanceAssessment::query()->count())->toBe(2);
});

it('misses the cache when the model, the prompt version, or the rules version changes', function () {
    setImportanceThreshold(70);
    $judge = new RecordingJudge(semanticAssessment());
    $candidate = neutralCandidate();

    importanceClassifier($judge)->classify('classifier', $candidate);
    importanceClassifier($judge, model: 'claude-other')->classify('classifier', $candidate);
    importanceClassifier($judge, promptVersion: 'v2')->classify('classifier', $candidate);
    // 'rules-other' is a sentinel that must never equal DeterministicImportanceRules::VERSION,
    // otherwise this run would be a cache HIT on the first one and the assertion below would
    // silently stop testing the rules-version dimension of the cache key.
    importanceClassifier($judge, rules: new DeterministicImportanceRules('rules-other'))->classify('classifier', $candidate);

    expect(DeterministicImportanceRules::VERSION)->not->toBe('rules-other')
        ->and($judge->calls)->toBe(4)
        ->and(ImportanceAssessment::query()->count())->toBe(4)
        ->and(ImportanceAssessment::query()->pluck('rules_version')->all())->toContain('rules-other');
});

it('retries a failed assessment instead of treating it as a cache hit', function () {
    setImportanceThreshold(70);
    $candidate = neutralCandidate();

    $failing = new RecordingJudge(ImportanceClassificationException::timedOut());
    $failed = importanceClassifier($failing)->classify('classifier', $candidate);

    expect($failed->errorCode)->toBe('timeout')
        ->and($failed->verdict)->toBeNull()
        ->and(ImportanceAssessment::query()->sole()->status)->toBe(ImportanceAssessmentStatus::Failed);

    $succeeding = new RecordingJudge(semanticAssessment());
    $retried = importanceClassifier($succeeding)->classify('classifier', $candidate);

    expect($succeeding->calls)->toBe(1)
        ->and($retried->cacheHit)->toBeFalse()
        ->and($retried->errorCode)->toBeNull()
        ->and($retried->finalScore)->toBe(60)
        ->and(ImportanceAssessment::query()->count())->toBe(1);

    $assessment = ImportanceAssessment::query()->sole();

    expect($assessment->status)->toBe(ImportanceAssessmentStatus::Succeeded)
        ->and($assessment->error_code)->toBeNull()
        ->and($assessment->error_message)->toBeNull();
});

it('persists a sanitized failure and never leaks the candidate or a raw process error', function () {
    setImportanceThreshold(70);
    $judge = new RecordingJudge(ImportanceClassificationException::processFailed(127));

    $result = importanceClassifier($judge)->classify('classifier', neutralCandidate());

    $assessment = ImportanceAssessment::query()->sole();

    expect($result->errorCode)->toBe('process_failed')
        ->and($result->semanticScore)->toBeNull()
        ->and($result->finalScore)->toBeNull()
        ->and($result->verdict)->toBeNull()
        ->and($result->reasons)->toBe([])
        ->and($assessment->status)->toBe(ImportanceAssessmentStatus::Failed)
        ->and($assessment->error_code)->toBe('process_failed')
        ->and($assessment->error_message)->toBe('Claude importance process exited with a non-zero status (127).')
        ->and($assessment->semantic_score)->toBeNull()
        ->and($assessment->verdict)->toBeNull()
        ->and($assessment->reasons)->toBe([]);
});

it('never consults the judge for a hard-vetoed candidate', function () {
    setImportanceThreshold(70);
    $judge = new RecordingJudge(semanticAssessment());

    $result = importanceClassifier($judge)->classify('classifier', new ImportanceCandidate(
        title: 'Next steps',
        content: 'TODO. TBD. N/A. Placeholder.',
        category: 'insight',
        source: 'condense',
    ));

    expect($judge->calls)->toBe(0)
        ->and($result->semanticScore)->toBeNull()
        ->and($result->finalScore)->toBe(0)
        ->and($result->verdict)->toBe(ImportanceVerdict::NotImportant)
        ->and($result->errorCode)->toBeNull()
        ->and(array_column($result->triggeredRules, 'id'))->toContain('placeholder_only');

    $assessment = ImportanceAssessment::query()->sole();

    expect($assessment->status)->toBe(ImportanceAssessmentStatus::Succeeded)
        ->and($assessment->final_score)->toBe(0)
        ->and($assessment->verdict)->toBe(ImportanceVerdict::NotImportant)
        ->and($assessment->semantic_score)->toBeNull();
});

it('keeps a hard-vetoed candidate not important even at a threshold of zero', function () {
    setImportanceThreshold(0);
    $judge = new RecordingJudge(semanticAssessment());
    $noise = new ImportanceCandidate(
        title: 'Next steps',
        content: 'TODO. TBD. N/A. Placeholder.',
        category: 'insight',
        source: 'condense',
    );

    $fresh = importanceClassifier($judge)->classify('classifier', $noise);
    $cached = importanceClassifier($judge)->classify('classifier', $noise);

    expect($judge->calls)->toBe(0)
        ->and($fresh->verdict)->toBe(ImportanceVerdict::NotImportant)
        ->and($cached->cacheHit)->toBeTrue()
        ->and($cached->finalScore)->toBe(0)
        ->and($cached->verdict)->toBe(ImportanceVerdict::NotImportant)
        ->and(ImportanceAssessment::query()->sole()->verdict)->toBe(ImportanceVerdict::NotImportant);
});

it('reads the winning assessment when it loses a unique-key race', function () {
    setImportanceThreshold(70);
    $judge = new RecordingJudge(semanticAssessment());
    $candidate = neutralCandidate();

    // Another worker inserts the same cache identity in the window between our
    // lookup and our insert, and finishes it first.
    raceInsertAssessment(status: 'succeeded', extra: [
        'semantic_score' => 88,
        'final_score' => 88,
        'verdict' => 'important',
        'reasons' => json_encode([['criterion' => 'durability', 'explanation' => 'Winner reason.']]),
        'rules' => json_encode([]),
    ]);

    $result = importanceClassifier($judge)->classify('classifier', $candidate);

    expect($judge->calls)->toBe(0)
        ->and($result->cacheHit)->toBeTrue()
        ->and($result->finalScore)->toBe(88)
        ->and($result->verdict)->toBe(ImportanceVerdict::Important)
        ->and($result->reasons)->toEqual([['criterion' => 'durability', 'explanation' => 'Winner reason.']])
        ->and(ImportanceAssessment::query()->count())->toBe(1);
});

it('raises a transient failure when the race winner is still running', function () {
    setImportanceThreshold(70);
    $judge = new RecordingJudge(semanticAssessment());

    raceInsertAssessment(status: 'running');

    expect(fn () => importanceClassifier($judge)->classify('classifier', neutralCandidate()))
        ->toThrow(ImportanceAssessmentInProgressException::class);

    expect($judge->calls)->toBe(0)
        ->and(ImportanceAssessment::query()->count())->toBe(1);
});

it('raises a transient failure when another worker already owns the running assessment', function () {
    setImportanceThreshold(70);
    $judge = new RecordingJudge(semanticAssessment());
    $candidate = neutralCandidate();

    ImportanceAssessment::create([
        'project_id' => 'classifier',
        'candidate_hash' => (new ImportanceCandidateNormalizer)->normalize($candidate)->hash(),
        'normalized_candidate' => (new ImportanceCandidateNormalizer)->normalize($candidate)->data(),
        'model' => 'claude-test',
        'prompt_version' => 'v1',
        'rules_version' => DeterministicImportanceRules::VERSION,
        'status' => ImportanceAssessmentStatus::Running,
    ]);

    expect(fn () => importanceClassifier($judge)->classify('classifier', $candidate))
        ->toThrow(ImportanceAssessmentInProgressException::class);

    expect($judge->calls)->toBe(0);
});

it('reclaims a running assessment abandoned by a crashed worker', function () {
    setImportanceThreshold(70);
    $judge = new RecordingJudge(semanticAssessment());
    $candidate = neutralCandidate();

    // A worker died mid-classification and left its `running` row behind: it is
    // older than the stale interval, so it is not live contention.
    $abandoned = runningAssessment($candidate, staleMinutes: STALE_AFTER_MINUTES + 5);

    $result = importanceClassifier($judge)->classify('classifier', $candidate);

    expect($judge->calls)->toBe(1)
        ->and($result->cacheHit)->toBeFalse()
        ->and($result->finalScore)->toBe(60)
        ->and($result->verdict)->toBe(ImportanceVerdict::NotImportant)
        ->and(ImportanceAssessment::query()->count())->toBe(1);

    $abandoned->refresh();

    expect($abandoned->status)->toBe(ImportanceAssessmentStatus::Succeeded)
        ->and($abandoned->semantic_score)->toBe(60);
});

it('still steps aside for a running assessment that is not yet stale', function () {
    setImportanceThreshold(70);
    $judge = new RecordingJudge(semanticAssessment());
    $candidate = neutralCandidate();

    runningAssessment($candidate, staleMinutes: STALE_AFTER_MINUTES - 1);

    expect(fn () => importanceClassifier($judge)->classify('classifier', $candidate))
        ->toThrow(ImportanceAssessmentInProgressException::class);

    expect($judge->calls)->toBe(0)
        ->and(ImportanceAssessment::query()->sole()->status)->toBe(ImportanceAssessmentStatus::Running);
});

it('lets only one worker reclaim the same stale assessment', function () {
    setImportanceThreshold(70);
    $judge = new RecordingJudge(semanticAssessment());
    $candidate = neutralCandidate();

    $abandoned = runningAssessment($candidate, staleMinutes: STALE_AFTER_MINUTES + 5);

    // A competing worker reclaims the stale row in the window between our lookup
    // and our conditional update: our update must match no row, and we must step
    // aside rather than run a second judgement against the same identity.
    raceReclaimAssessment($abandoned);

    expect(fn () => importanceClassifier($judge)->classify('classifier', $candidate))
        ->toThrow(ImportanceAssessmentInProgressException::class);

    expect($judge->calls)->toBe(0)
        ->and(ImportanceAssessment::query()->count())->toBe(1)
        ->and(ImportanceAssessment::query()->sole()->status)->toBe(ImportanceAssessmentStatus::Running);
});

it('rethrows a database failure that is not a unique-key race', function () {
    setImportanceThreshold(70);
    $judge = new RecordingJudge(semanticAssessment());

    // A column overflow (SQLSTATE 22001), not a unique violation (23505): it must
    // never be mistaken for a lost race and swallowed.
    ImportanceAssessment::creating(function (ImportanceAssessment $assessment): void {
        $assessment->model = str_repeat('m', 300);
    });

    expect(fn () => importanceClassifier($judge)->classify('classifier', neutralCandidate()))
        ->toThrow(QueryException::class);

    expect($judge->calls)->toBe(0)
        ->and(ImportanceAssessment::query()->count())->toBe(0);
});

it('resolves from the container with the configured model and prompt version', function () {
    $classifier = app(HybridImportanceClassifier::class);

    expect($classifier)->toBeInstanceOf(HybridImportanceClassifier::class);

    // Drive it through the veto path so it never reaches the real (container
    // bound) semantic judge, and assert the model/prompt version it actually
    // used come from config, not just that some instance was built.
    $result = $classifier->classify('classifier', new ImportanceCandidate(
        title: 'Empty note',
        content: '',
        category: 'insight',
        source: 'condense',
    ));

    expect($result->model)->toBe((string) config('rag.importance.model'))
        ->and($result->promptVersion)->toBe((string) config('rag.importance.prompt_version'));
});

/**
 * A `running` assessment for `$candidate` whose last write was `$staleMinutes`
 * ago — i.e. a worker that is either still busy (fresh) or that crashed and left
 * the row behind (older than the classifier's stale interval).
 */
function runningAssessment(ImportanceCandidate $candidate, int $staleMinutes): ImportanceAssessment
{
    $normalized = (new ImportanceCandidateNormalizer)->normalize($candidate);

    $assessment = ImportanceAssessment::create([
        'project_id' => 'classifier',
        'candidate_hash' => $normalized->hash(),
        'normalized_candidate' => $normalized->data(),
        'model' => 'claude-test',
        'prompt_version' => 'v1',
        'rules_version' => DeterministicImportanceRules::VERSION,
        'status' => ImportanceAssessmentStatus::Running,
    ]);

    // Backdate through the query builder so Eloquent's timestamps do not
    // immediately overwrite it.
    DB::table('importance_assessments')
        ->where('id', $assessment->getKey())
        ->update(['updated_at' => now()->subMinutes($staleMinutes)]);

    return $assessment->refresh();
}

/**
 * Simulate losing the reclamation race: a competing worker takes the stale row
 * over (bumping `updated_at`) right after our lookup reads it.
 */
function raceReclaimAssessment(ImportanceAssessment $assessment): void
{
    $reclaimed = false;

    DB::listen(function (QueryExecuted $query) use (&$reclaimed, $assessment): void {
        if ($reclaimed || ! str_contains($query->sql, 'select * from "importance_assessments"')) {
            return;
        }

        $reclaimed = true;

        DB::table('importance_assessments')
            ->where('id', $assessment->getKey())
            ->update(['updated_at' => now()]);
    });
}

/**
 * Simulate losing a cache-identity race: a competing worker writes the same
 * identity in the window between our lookup and our insert.
 *
 * The competing row is written right after the classifier's lookup query runs
 * (and therefore before the classifier opens its own transaction), so it is a
 * faithful stand-in for another worker's committed row: the classifier's insert
 * hits the real unique constraint, and rolling its own insert back does not undo
 * the winner.
 *
 * @param  array<string, mixed>  $extra
 */
function raceInsertAssessment(string $status, array $extra = []): void
{
    $normalized = (new ImportanceCandidateNormalizer)->normalize(neutralCandidate());
    $inserted = false;

    DB::listen(function (QueryExecuted $query) use (&$inserted, $normalized, $status, $extra): void {
        if ($inserted || ! str_contains($query->sql, 'select * from "importance_assessments"')) {
            return;
        }

        $inserted = true;

        DB::table('importance_assessments')->insert([
            'project_id' => 'classifier',
            'candidate_hash' => $normalized->hash(),
            'normalized_candidate' => $normalized->json(),
            'model' => 'claude-test',
            'prompt_version' => 'v1',
            'rules_version' => DeterministicImportanceRules::VERSION,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
            ...$extra,
        ]);
    });
}
