<?php

use App\Enums\ImportanceAssessmentStatus;
use App\Enums\ImportanceClassifierMode;
use App\Enums\ImportanceVerdict;
use App\Enums\KnowledgeStatus;
use App\Jobs\ClassifyKnowledgeEntryJob;
use App\Jobs\IndexEntryJob;
use App\Models\ImportanceAssessment;
use App\Models\ImportanceClassifierSetting;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Services\Importance\DeterministicImportanceRules;
use App\Services\Importance\ImportanceAssessmentInProgressException;
use App\Services\Importance\ImportanceCandidate;
use App\Services\Importance\ImportanceCandidateNormalizer;
use App\Services\Importance\ImportanceClassificationException;
use App\Services\Importance\ImportancePrompt;
use App\Services\Importance\NormalizedImportanceCandidate;
use App\Services\Importance\SemanticImportanceAssessment;
use App\Services\Importance\SemanticImportanceJudge;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * The semantic half, faked: it never launches the `claude` binary, counts how
 * often it was consulted (so "did not re-judge" is provable), and can raise the
 * exact failures the real judge raises — including a raw, unsanitized one.
 */
final class SpyImportanceJudge implements SemanticImportanceJudge
{
    public int $calls = 0;

    public function __construct(
        private readonly SemanticImportanceAssessment|Throwable $response,
    ) {}

    public function assess(NormalizedImportanceCandidate $candidate): SemanticImportanceAssessment
    {
        $this->calls++;

        if ($this->response instanceof Throwable) {
            throw $this->response;
        }

        return $this->response;
    }
}

beforeEach(function () {
    // The transition out of `classifying` makes the observer schedule indexing;
    // faking the queue keeps the real embedder out of this suite.
    Queue::fake();
    Project::create(['id' => 'p1', 'name' => 'p1', 'root_path' => '/tmp/p1']);
});

/**
 * Deliberately unremarkable content: it triggers no deterministic rule at all,
 * so the final score is exactly the faked semantic score.
 */
const CLASSIFIABLE_CONTENT = 'The InvoiceNumberAllocator hands out one number per fiscal year and the relay worker ships the row to the broker on the next tick of the loop.';

/**
 * @param  array<string, mixed>  $metadata
 */
function classifyingEntry(array $metadata = [], string $status = 'classifying', ?string $content = null): KnowledgeEntry
{
    return KnowledgeEntry::create([
        'project_id' => 'p1',
        'title' => 'Invoice numbering',
        'content' => $content ?? CLASSIFIABLE_CONTENT,
        'category' => 'insight',
        'source' => 'condense',
        'status' => $status,
        'metadata' => $metadata,
    ]);
}

/** Sums to 75: above the default threshold of 70, so the verdict is `important`. */
function importantJudgement(): SemanticImportanceAssessment
{
    return judgement(20, 15, 15, 15, 10);
}

/** Sums to 60: below the default threshold of 70, so the verdict is `not_important`. */
function unimportantJudgement(): SemanticImportanceAssessment
{
    return judgement(15, 12, 12, 12, 9);
}

function judgement(int $durability, int $actionability, int $specificity, int $nonObviousness, int $futureValue): SemanticImportanceAssessment
{
    return new SemanticImportanceAssessment(
        durability: $durability,
        actionability: $actionability,
        specificity: $specificity,
        nonObviousness: $nonObviousness,
        futureValue: $futureValue,
        semanticScore: $durability + $actionability + $specificity + $nonObviousness + $futureValue,
        recommendedVerdict: ImportanceVerdict::Important,
        reasons: [['criterion' => 'durability', 'explanation' => 'The rule outlives the session.']],
    );
}

/**
 * @param  ImportanceClassifierMode|value-of<ImportanceClassifierMode>  $mode
 */
function settings(ImportanceClassifierMode|string $mode, int $threshold = 70, ?int $autoApprove = null): void
{
    ImportanceClassifierSetting::query()->findOrFail(1)->update([
        'mode' => $mode instanceof ImportanceClassifierMode ? $mode->value : $mode,
        'threshold' => $threshold,
        'auto_approve_threshold' => $autoApprove,
    ]);
}

function spyJudge(SemanticImportanceAssessment|Throwable $response): SpyImportanceJudge
{
    $judge = new SpyImportanceJudge($response);

    app()->instance(SemanticImportanceJudge::class, $judge);

    return $judge;
}

/**
 * A semantic assessment carrying only the total score that matters to the
 * final-score arithmetic; the individual criterion scores are irrelevant to
 * every test that uses this helper.
 */
function fakeJudge(int $semanticScore): SpyImportanceJudge
{
    return spyJudge(new SemanticImportanceAssessment(
        durability: $semanticScore,
        actionability: 0,
        specificity: 0,
        nonObviousness: 0,
        futureValue: 0,
        semanticScore: $semanticScore,
        recommendedVerdict: ImportanceVerdict::Important,
        reasons: [],
    ));
}

/** A judge that always raises a terminal, technical failure — never a verdict. */
function failingJudge(): SpyImportanceJudge
{
    return spyJudge(ImportanceClassificationException::timedOut());
}

/**
 * Note: this is deliberately NOT named `runClassificationJob()` — that name is
 * already a global function in `tests/Feature/ImportanceClassifierWorkflowTest.php`
 * (Pest test-file helpers share one global namespace across the whole run), and
 * redeclaring it would be a fatal error the moment the full suite runs both files.
 */
function runClassification(KnowledgeEntry $entry): void
{
    app()->call([new ClassifyKnowledgeEntryJob((int) $entry->id), 'handle']);
}

it('runs on the classification queue with bounded retries and a timeout above the model timeout', function () {
    $job = new ClassifyKnowledgeEntryJob(1);

    expect($job->queue)->toBe('classification')
        ->and($job->queue)->toBe((string) config('rag.importance.queue'))
        ->and($job->connection)->toBe('classification')
        ->and($job->connection)->toBe((string) config('rag.importance.queue_connection'))
        ->and($job->tries)->toBe(3)
        ->and($job->timeout)->toBeGreaterThan((int) config('rag.importance.timeout'))
        ->and($job->backoff())->toBe([30, 60]);
});

it('sizes the classification queue connection so retry_after strictly exceeds the job timeout', function () {
    // Pins the invariant Finding 1 exists to protect: a job still running near
    // its own $timeout must not be eligible for re-reservation by a second
    // worker. `retry_after` is read from the *connection* the worker listens
    // on (config/queue.php), not from the job's own properties, so both must
    // be checked — and the connection's value must be derived from the same
    // formula as the job's $timeout, not a second, independently-typed number.
    $job = new ClassifyKnowledgeEntryJob(1);
    $retryAfter = (int) config('queue.connections.classification.retry_after');

    expect($retryAfter)->toBeGreaterThan($job->timeout)
        ->and($retryAfter)->toBe(ClassifyKnowledgeEntryJob::classificationRetryAfterSeconds(
            (int) config('rag.importance.timeout'),
        ));
});

it('keeps a shadow-mode important entry pending with its verdict snapshot', function () {
    settings(ImportanceClassifierMode::Shadow);
    $judge = spyJudge(importantJudgement());
    $entry = classifyingEntry();

    runClassification($entry);

    $entry->refresh();
    $assessment = ImportanceAssessment::query()->sole();
    $importance = $entry->metadata['importance'];

    expect($judge->calls)->toBe(1)
        ->and($entry->status)->toBe(KnowledgeStatus::Pending->value)
        ->and($entry->importance_assessment_id)->toBe($assessment->id)
        ->and($assessment->status)->toBe(ImportanceAssessmentStatus::Succeeded)
        ->and($assessment->verdict)->toBe(ImportanceVerdict::Important)
        ->and($importance['final_score'])->toBe(75)
        ->and($importance['semantic_score'])->toBe(75)
        ->and($importance['verdict'])->toBe(ImportanceVerdict::Important->value)
        ->and($importance['mode'])->toBe(ImportanceClassifierMode::Shadow->value)
        ->and($importance['would_reject'])->toBeFalse()
        ->and($importance['model'])->toBe((string) config('rag.importance.model'))
        ->and($importance['prompt_version'])->toBe(ImportancePrompt::VERSION)
        ->and($importance['rules_version'])->toBe(DeterministicImportanceRules::VERSION)
        ->and($importance['candidate_hash'])->toBe($assessment->candidate_hash)
        ->and($importance['cache_hit'])->toBeFalse()
        ->and($importance['reasons'])->toEqual([['criterion' => 'durability', 'explanation' => 'The rule outlives the session.']])
        ->and($importance['rules'])->toBe([])
        ->and($importance)->not->toHaveKey('classification_error');
});

it('keeps a shadow-mode unimportant entry pending but marks it as one it would reject', function () {
    settings(ImportanceClassifierMode::Shadow);
    spyJudge(unimportantJudgement());
    $entry = classifyingEntry();

    runClassification($entry);

    $entry->refresh();
    $importance = $entry->metadata['importance'];

    expect($entry->status)->toBe(KnowledgeStatus::Pending->value)
        ->and($entry->importance_assessment_id)->toBe(ImportanceAssessment::query()->sole()->id)
        ->and($importance['final_score'])->toBe(60)
        ->and($importance['verdict'])->toBe(ImportanceVerdict::NotImportant->value)
        ->and($importance['mode'])->toBe(ImportanceClassifierMode::Shadow->value)
        ->and($importance['would_reject'])->toBeTrue();
});

it('keeps an enforce-mode important entry pending', function () {
    settings(ImportanceClassifierMode::Enforce);
    spyJudge(importantJudgement());
    $entry = classifyingEntry();

    runClassification($entry);

    $entry->refresh();
    $importance = $entry->metadata['importance'];

    expect($entry->status)->toBe(KnowledgeStatus::Pending->value)
        ->and($entry->importance_assessment_id)->toBe(ImportanceAssessment::query()->sole()->id)
        ->and($importance['final_score'])->toBe(75)
        ->and($importance['verdict'])->toBe(ImportanceVerdict::Important->value)
        ->and($importance['mode'])->toBe(ImportanceClassifierMode::Enforce->value)
        ->and($importance['would_reject'])->toBeFalse()
        ->and($importance['prompt_version'])->toBe(ImportancePrompt::VERSION)
        ->and($importance['rules_version'])->toBe(DeterministicImportanceRules::VERSION);

    Queue::assertPushed(IndexEntryJob::class, 1);
});

it('rejects an enforce-mode unimportant entry', function () {
    settings(ImportanceClassifierMode::Enforce);
    spyJudge(unimportantJudgement());
    $entry = classifyingEntry();

    runClassification($entry);

    $entry->refresh();
    $importance = $entry->metadata['importance'];

    expect($entry->status)->toBe(KnowledgeStatus::Rejected->value)
        ->and($entry->importance_assessment_id)->toBe(ImportanceAssessment::query()->sole()->id)
        ->and($importance['final_score'])->toBe(60)
        ->and($importance['verdict'])->toBe(ImportanceVerdict::NotImportant->value)
        ->and($importance['mode'])->toBe(ImportanceClassifierMode::Enforce->value)
        ->and($importance['would_reject'])->toBeTrue()
        ->and($importance['model'])->toBe((string) config('rag.importance.model'))
        ->and($importance['rules_version'])->toBe(DeterministicImportanceRules::VERSION);

    // A rejected entry is never indexed.
    Queue::assertNotPushed(IndexEntryJob::class);
});

it('releases an entry to pending without an assessment when the mode was switched off mid-flight', function () {
    settings(ImportanceClassifierMode::Off);
    $judge = spyJudge(importantJudgement());
    $entry = classifyingEntry();

    runClassification($entry);

    $entry->refresh();

    expect($judge->calls)->toBe(0)
        ->and($entry->status)->toBe(KnowledgeStatus::Pending->value)
        ->and($entry->importance_assessment_id)->toBeNull()
        ->and($entry->metadata)->not->toHaveKey('importance')
        ->and(ImportanceAssessment::query()->count())->toBe(0);

    // Released to pending like every other release path, so it must be
    // indexed the same way — otherwise it is invisible to search forever.
    Queue::assertPushed(IndexEntryJob::class, 1);
});

it('writes the verdict snapshot without touching unrelated metadata keys', function () {
    settings(ImportanceClassifierMode::Shadow);
    spyJudge(importantJudgement());
    $entry = classifyingEntry(['source_path' => '/sessions/42.jsonl', 'condense_run_id' => 7]);

    runClassification($entry);

    $entry->refresh();

    expect($entry->metadata['source_path'])->toBe('/sessions/42.jsonl')
        ->and($entry->metadata['condense_run_id'])->toBe(7)
        ->and($entry->metadata['importance']['verdict'])->toBe(ImportanceVerdict::Important->value);
});

it('does nothing to an entry that has already left classifying', function (string $status) {
    settings(ImportanceClassifierMode::Enforce);
    $judge = spyJudge(unimportantJudgement());
    $entry = classifyingEntry(['reviewer' => 'human'], status: $status);

    runClassification($entry);

    $entry->refresh();

    expect($judge->calls)->toBe(0)
        ->and($entry->status)->toBe($status)
        ->and($entry->metadata)->toBe(['reviewer' => 'human'])
        ->and($entry->importance_assessment_id)->toBeNull()
        ->and(ImportanceAssessment::query()->count())->toBe(0);
})->with([
    KnowledgeStatus::Pending->value,
    KnowledgeStatus::Approved->value,
    KnowledgeStatus::Rejected->value,
]);

it('does not clobber a human decision taken while the classification was in flight', function () {
    settings(ImportanceClassifierMode::Enforce);
    $entry = classifyingEntry();

    // The reviewer approves the entry while the judge is still deliberating: the
    // guarded transition must find no `classifying` row and leave the decision
    // alone, however unimportant the classifier thought the entry was.
    $judge = new SpyImportanceJudge(unimportantJudgement());
    app()->instance(SemanticImportanceJudge::class, new class($judge, $entry) implements SemanticImportanceJudge
    {
        public function __construct(
            private readonly SpyImportanceJudge $judge,
            private readonly KnowledgeEntry $entry,
        ) {}

        public function assess(NormalizedImportanceCandidate $candidate): SemanticImportanceAssessment
        {
            KnowledgeEntry::query()
                ->whereKey($this->entry->id)
                ->update(['status' => KnowledgeStatus::Approved->value]);

            return $this->judge->assess($candidate);
        }
    });

    runClassification($entry);

    $entry->refresh();

    expect($judge->calls)->toBe(1)
        ->and($entry->status)->toBe(KnowledgeStatus::Approved->value)
        ->and($entry->metadata)->not->toHaveKey('importance')
        ->and($entry->importance_assessment_id)->toBeNull();
});

it('is harmless when the job is delivered twice', function () {
    settings(ImportanceClassifierMode::Shadow);
    $judge = spyJudge(importantJudgement());
    $entry = classifyingEntry();

    runClassification($entry);
    runClassification($entry);

    $entry->refresh();

    expect($judge->calls)->toBe(1)
        ->and($entry->status)->toBe(KnowledgeStatus::Pending->value)
        ->and(ImportanceAssessment::query()->count())->toBe(1)
        ->and($entry->metadata['importance']['final_score'])->toBe(75);

    Queue::assertPushed(IndexEntryJob::class, 1);
});

it('retries a transient contention failure instead of failing the entry open', function () {
    settings(ImportanceClassifierMode::Shadow);
    $judge = spyJudge(importantJudgement());
    $entry = classifyingEntry();

    // Another worker already owns the `running` assessment for this identity.
    runningAssessmentFor($entry);

    expect(fn () => runClassification($entry))
        ->toThrow(ImportanceAssessmentInProgressException::class);

    $entry->refresh();

    expect($judge->calls)->toBe(0)
        ->and($entry->status)->toBe(KnowledgeStatus::Classifying->value)
        ->and($entry->metadata)->not->toHaveKey('importance');

    Queue::assertNotPushed(IndexEntryJob::class);
});

it('fails open to pending with a sanitized error on every terminal technical failure', function (Throwable $failure, string $code, string $message) {
    // Enforce mode: even here, a technical failure must never reject.
    settings(ImportanceClassifierMode::Enforce);
    spyJudge($failure);
    $entry = classifyingEntry(['source_path' => '/sessions/42.jsonl']);

    runClassification($entry);

    $entry->refresh();
    $error = $entry->metadata['importance']['classification_error'];

    expect($entry->status)->toBe(KnowledgeStatus::Pending->value)
        ->and($entry->status)->not->toBe(KnowledgeStatus::Rejected->value)
        ->and($entry->metadata['source_path'])->toBe('/sessions/42.jsonl')
        ->and($entry->metadata['importance'])->not->toHaveKey('verdict')
        ->and($entry->metadata['importance']['mode'])->toBe(ImportanceClassifierMode::Enforce->value)
        ->and($error['code'])->toBe($code)
        ->and($error['message'])->toBe($message)
        ->and($error['message'])->not->toContain('speculative reasoning')
        ->and($error['message'])->not->toContain(CLASSIFIABLE_CONTENT)
        ->and($error['model'])->toBe((string) config('rag.importance.model'))
        ->and($error['prompt_version'])->toBe(ImportancePrompt::VERSION)
        ->and($error['rules_version'])->toBe(DeterministicImportanceRules::VERSION);

    Queue::assertPushed(IndexEntryJob::class, 1);
})->with([
    'timeout' => fn () => [
        ImportanceClassificationException::timedOut(),
        'timeout',
        'Claude importance process timed out.',
    ],
    'unavailable binary' => fn () => [
        ImportanceClassificationException::processFailed(127),
        'process_failed',
        'Claude importance process exited with a non-zero status (127).',
    ],
    'invalid json' => fn () => [
        ImportanceClassificationException::invalidJson(),
        'invalid_json',
        'Claude importance response was not valid JSON.',
    ],
    'invalid schema' => fn () => [
        ImportanceClassificationException::invalidSchema('durability is missing'),
        'invalid_schema',
        'Claude importance response did not match the expected contract: durability is missing',
    ],
    'unexpected exception' => fn () => [
        new RuntimeException('raw stderr: speculative reasoning about '.CLASSIFIABLE_CONTENT),
        'unexpected_error',
        'The importance classifier failed unexpectedly.',
    ],
]);

it('stamps the failOpen audit record with the class constants, not a config value that may be stale', function () {
    // `php artisan config:cache` snapshots config/rag.php, and the
    // `bootstrap/cache` volume can outlive the image it was built from — so the
    // snapshot can lag the code by a whole version bump. failOpen() is the
    // one-hop-away instance of that same bug: if it read the versions through
    // config() instead of the class constants, a stale cache would attribute a
    // technical-failure audit record to the wrong prompt/rules. Simulate
    // exactly that stale cache and drive a TERMINAL failure through in
    // `enforce` mode, mirroring the success-path pin in
    // HybridImportanceClassifierTest ("keys the cache identity on the class
    // constants...").
    config([
        'rag.importance.prompt_version' => 'stale-cached-v0',
        'rag.importance.rules_version' => 'stale-cached-v0',
    ]);

    settings(ImportanceClassifierMode::Enforce);
    spyJudge(ImportanceClassificationException::timedOut());
    $entry = classifyingEntry();

    runClassification($entry);

    $entry->refresh();
    $error = $entry->metadata['importance']['classification_error'];

    expect($error['prompt_version'])->toBe(ImportancePrompt::VERSION)
        ->and($error['rules_version'])->toBe(DeterministicImportanceRules::VERSION)
        ->and($error['prompt_version'])->not->toBe('stale-cached-v0')
        ->and($error['rules_version'])->not->toBe('stale-cached-v0');
});

it('never logs the raw failure text of an unexpected exception', function () {
    settings(ImportanceClassifierMode::Shadow);
    spyJudge(new RuntimeException('raw stderr: speculative reasoning about '.CLASSIFIABLE_CONTENT));
    $entry = classifyingEntry();

    $logged = [];
    Log::listen(function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message.' '.json_encode($message->context);
    });

    runClassification($entry);

    expect($logged)->not->toBeEmpty()
        ->and(implode("\n", $logged))
        ->not->toContain('speculative reasoning')
        ->and(implode("\n", $logged))
        ->not->toContain(CLASSIFIABLE_CONTENT)
        ->and(implode("\n", $logged))
        ->toContain(RuntimeException::class);
});

it('records the failed assessment so the failure is auditable', function () {
    settings(ImportanceClassifierMode::Enforce);
    spyJudge(ImportanceClassificationException::timedOut());
    $entry = classifyingEntry();

    runClassification($entry);

    $assessment = ImportanceAssessment::query()->sole();

    expect($assessment->status)->toBe(ImportanceAssessmentStatus::Failed)
        ->and($assessment->error_code)->toBe('timeout')
        ->and($assessment->verdict)->toBeNull()
        ->and($entry->refresh()->importance_assessment_id)->toBeNull();
});

it('fails the entry open from its terminal failure handler', function () {
    settings(ImportanceClassifierMode::Enforce);
    $entry = classifyingEntry(['source_path' => '/sessions/42.jsonl']);

    (new ClassifyKnowledgeEntryJob((int) $entry->id))->failed(new RuntimeException('raw stderr: leaking'));

    $entry->refresh();
    $error = $entry->metadata['importance']['classification_error'];

    expect($entry->status)->toBe(KnowledgeStatus::Pending->value)
        ->and($entry->metadata['source_path'])->toBe('/sessions/42.jsonl')
        ->and($error['code'])->toBe('unexpected_error')
        ->and($error['message'])->not->toContain('leaking');

    Queue::assertPushed(IndexEntryJob::class, 1);
});

it('records exhausted contention retries as a transient failure when it fails the entry open', function () {
    settings(ImportanceClassifierMode::Shadow);
    $entry = classifyingEntry();

    (new ClassifyKnowledgeEntryJob((int) $entry->id))
        ->failed(ImportanceAssessmentInProgressException::make());

    $entry->refresh();

    expect($entry->status)->toBe(KnowledgeStatus::Pending->value)
        ->and($entry->metadata['importance']['classification_error']['code'])
        ->toBe(ImportanceAssessmentInProgressException::ERROR_CODE);
});

it('is idempotent in its terminal failure handler', function () {
    settings(ImportanceClassifierMode::Shadow);
    spyJudge(importantJudgement());
    $entry = classifyingEntry();

    runClassification($entry);

    $entry->refresh();
    $classified = $entry->metadata;

    expect($entry->status)->toBe(KnowledgeStatus::Pending->value);

    // A late terminal failure for the same delivery must not overwrite the
    // verdict the entry already carries, nor push a second index job.
    (new ClassifyKnowledgeEntryJob((int) $entry->id))->failed(new RuntimeException('too late'));
    (new ClassifyKnowledgeEntryJob((int) $entry->id))->failed(new RuntimeException('too late'));

    $entry->refresh();

    expect($entry->status)->toBe(KnowledgeStatus::Pending->value)
        ->and($entry->metadata)->toEqual($classified)
        ->and($entry->metadata['importance'])->not->toHaveKey('classification_error');

    Queue::assertPushed(IndexEntryJob::class, 1);
});

it('does not propagate when its own recovery write fails', function () {
    settings(ImportanceClassifierMode::Enforce);
    $entry = classifyingEntry();

    // Laravel never retries `failed()`; if the DB write it performs throws,
    // that must be swallowed and logged rather than propagated, or the entry
    // is stranded in `classifying` with no further recovery attempt at all.
    DB::shouldReceive('transaction')->once()->andThrow(new RuntimeException('db down'));

    $logged = [];
    Log::listen(function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message;
    });

    (new ClassifyKnowledgeEntryJob((int) $entry->id))->failed(new RuntimeException('boom'));

    expect(implode("\n", $logged))->toContain('terminal recovery itself failed')
        ->and($entry->refresh()->status)->toBe(KnowledgeStatus::Classifying->value);
});

it('does nothing when the entry no longer exists', function () {
    settings(ImportanceClassifierMode::Shadow);
    $judge = spyJudge(importantJudgement());

    app()->call([new ClassifyKnowledgeEntryJob(999_999), 'handle']);
    (new ClassifyKnowledgeEntryJob(999_999))->failed(new RuntimeException('gone'));

    expect($judge->calls)->toBe(0)
        ->and(ImportanceAssessment::query()->count())->toBe(0);
});

it('reuses a cached assessment for a repeated candidate', function () {
    settings(ImportanceClassifierMode::Shadow);
    $judge = spyJudge(importantJudgement());

    $first = classifyingEntry();
    $second = classifyingEntry();

    runClassification($first);
    runClassification($second);

    expect($judge->calls)->toBe(1)
        ->and(ImportanceAssessment::query()->count())->toBe(1)
        ->and($second->refresh()->metadata['importance']['cache_hit'])->toBeTrue()
        ->and($second->importance_assessment_id)->toBe($first->refresh()->importance_assessment_id)
        ->and($second->status)->toBe(KnowledgeStatus::Pending->value);
});

/**
 * Content whose deterministic rules add +11 (`normative_restriction` + `causal_rationale`)
 * with no penalty at all: it carries a concrete anchor (`orders_table`, snake_case), sits
 * exactly at the `MIN_SUBSTANCE_WORDS` floor, and uses no speculative or transient
 * wording. Verified directly against `DeterministicImportanceRules::evaluate()` — the
 * design doc's original seeder sentence ("...the orders table.") falls just short of
 * eligibility, because two bare words read as `generic_without_context` (-8) under v6;
 * this is the same fact, spelled with a concrete anchor instead.
 */
const ELIGIBLE_CONTENT = 'Never run db:seed in production because it truncates the orders_table records permanently.';

it('auto-approves an eligible entry in enforce mode', function () {
    settings(mode: 'enforce', threshold: 70, autoApprove: 90);

    $entry = classifyingEntry(content: ELIGIBLE_CONTENT);

    fakeJudge(semanticScore: 88); // + normative_restriction(6) + causal_rationale(5) = 99

    runClassification($entry);

    $entry->refresh();
    $importance = $entry->metadata['importance'];

    expect($entry->status)->toBe(KnowledgeStatus::Approved->value)
        ->and($importance['auto_approved'])->toBeTrue()
        ->and($importance['would_approve'])->toBeTrue()
        ->and($importance['would_reject'])->toBeFalse()
        ->and($importance['verdict'])->toBe('important')
        ->and($importance['final_score'])->toBe(99);
});

it('leaves an important but ineligible entry to a human in enforce mode', function () {
    settings(mode: 'enforce', threshold: 70, autoApprove: 90);

    // High semantic score, but no positive deterministic signal fires.
    $entry = classifyingEntry(content: 'The embedder model file lives under storage/models and is 420 MB.');

    fakeJudge(semanticScore: 100);

    runClassification($entry);

    $entry->refresh();

    expect($entry->status)->toBe(KnowledgeStatus::Pending->value)
        ->and($entry->metadata['importance']['auto_approved'])->toBeFalse()
        ->and($entry->metadata['importance']['would_approve'])->toBeFalse();
})->note('The injection barrier: a perfect model score cannot approve on its own.');

it('records would_approve in shadow but never approves', function () {
    settings(mode: 'shadow', threshold: 70, autoApprove: 90);

    $entry = classifyingEntry(content: ELIGIBLE_CONTENT);

    fakeJudge(semanticScore: 88);

    runClassification($entry);

    $entry->refresh();

    expect($entry->status)->toBe(KnowledgeStatus::Pending->value)
        ->and($entry->metadata['importance']['would_approve'])->toBeTrue()
        ->and($entry->metadata['importance']['auto_approved'])->toBeFalse();
})->note('Shadow measures. It never acts.');

it('never auto-approves when the threshold is null', function () {
    settings(mode: 'enforce', threshold: 70, autoApprove: null);

    $entry = classifyingEntry(content: ELIGIBLE_CONTENT);

    fakeJudge(semanticScore: 88);

    runClassification($entry);

    $entry->refresh();

    expect($entry->status)->toBe(KnowledgeStatus::Pending->value)
        ->and($entry->metadata['importance']['would_approve'])->toBeFalse();
});

it('still rejects a not-important entry in enforce mode', function () {
    settings(mode: 'enforce', threshold: 70, autoApprove: 90);

    $entry = classifyingEntry(content: 'Maybe we should look into this at some point.');

    fakeJudge(semanticScore: 10);

    runClassification($entry);

    expect($entry->fresh()->status)->toBe(KnowledgeStatus::Rejected->value);
})->note('The reject path is unchanged.');

it('never auto-approves on a technical failure', function () {
    settings(mode: 'enforce', threshold: 70, autoApprove: 90);

    $entry = classifyingEntry(content: ELIGIBLE_CONTENT);

    failingJudge(); // throws a terminal ImportanceClassificationException

    runClassification($entry);

    $entry->refresh();

    expect($entry->status)->toBe(KnowledgeStatus::Pending->value)
        ->and($entry->metadata['importance'])->not->toHaveKey('auto_approved')
        ->and($entry->metadata['importance']['classification_error']['code'])->not->toBeNull();
})->note('Fail-open still governs: a failure approves nothing and rejects nothing.');

/**
 * Stand in for another worker that is mid-classification on the same cache
 * identity as `$entry`.
 */
function runningAssessmentFor(KnowledgeEntry $entry): ImportanceAssessment
{
    $normalized = (new ImportanceCandidateNormalizer)->normalize(new ImportanceCandidate(
        title: $entry->title,
        content: $entry->content,
        category: $entry->category,
        source: $entry->source,
    ));

    return ImportanceAssessment::create([
        'project_id' => $entry->project_id,
        'candidate_hash' => $normalized->hash(),
        'normalized_candidate' => $normalized->data(),
        'model' => (string) config('rag.importance.model'),
        'prompt_version' => ImportancePrompt::VERSION,
        'rules_version' => DeterministicImportanceRules::VERSION,
        'status' => ImportanceAssessmentStatus::Running,
    ]);
}
