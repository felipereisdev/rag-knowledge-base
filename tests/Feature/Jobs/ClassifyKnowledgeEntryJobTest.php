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
use App\Services\Importance\NormalizedImportanceCandidate;
use App\Services\Importance\SemanticImportanceAssessment;
use App\Services\Importance\SemanticImportanceJudge;
use Illuminate\Log\Events\MessageLogged;
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
function classifyingEntry(array $metadata = [], string $status = 'classifying'): KnowledgeEntry
{
    return KnowledgeEntry::create([
        'project_id' => 'p1',
        'title' => 'Invoice numbering',
        'content' => CLASSIFIABLE_CONTENT,
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

function classifierMode(ImportanceClassifierMode $mode, int $threshold = 70): void
{
    ImportanceClassifierSetting::query()->findOrFail(1)->update([
        'mode' => $mode->value,
        'threshold' => $threshold,
    ]);
}

function spyJudge(SemanticImportanceAssessment|Throwable $response): SpyImportanceJudge
{
    $judge = new SpyImportanceJudge($response);

    app()->instance(SemanticImportanceJudge::class, $judge);

    return $judge;
}

function runClassification(KnowledgeEntry $entry): void
{
    app()->call([new ClassifyKnowledgeEntryJob((int) $entry->id), 'handle']);
}

it('runs on the classification queue with bounded retries and a timeout above the model timeout', function () {
    $job = new ClassifyKnowledgeEntryJob(1);

    expect($job->queue)->toBe('classification')
        ->and($job->queue)->toBe((string) config('rag.importance.queue'))
        ->and($job->tries)->toBe(3)
        ->and($job->timeout)->toBeGreaterThan((int) config('rag.importance.timeout'))
        ->and($job->backoff())->toBeArray()
        ->and($job->backoff())->not->toBeEmpty();
});

it('keeps a shadow-mode important entry pending with its verdict snapshot', function () {
    classifierMode(ImportanceClassifierMode::Shadow);
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
        ->and($importance['prompt_version'])->toBe((string) config('rag.importance.prompt_version'))
        ->and($importance['rules_version'])->toBe(DeterministicImportanceRules::VERSION)
        ->and($importance['candidate_hash'])->toBe($assessment->candidate_hash)
        ->and($importance['cache_hit'])->toBeFalse()
        ->and($importance['reasons'])->toEqual([['criterion' => 'durability', 'explanation' => 'The rule outlives the session.']])
        ->and($importance['rules'])->toBe([])
        ->and($importance)->not->toHaveKey('classification_error');
});

it('keeps a shadow-mode unimportant entry pending but marks it as one it would reject', function () {
    classifierMode(ImportanceClassifierMode::Shadow);
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
    classifierMode(ImportanceClassifierMode::Enforce);
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
        ->and($importance['prompt_version'])->toBe((string) config('rag.importance.prompt_version'))
        ->and($importance['rules_version'])->toBe(DeterministicImportanceRules::VERSION);

    Queue::assertPushed(IndexEntryJob::class, 1);
});

it('rejects an enforce-mode unimportant entry', function () {
    classifierMode(ImportanceClassifierMode::Enforce);
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
    classifierMode(ImportanceClassifierMode::Off);
    $judge = spyJudge(importantJudgement());
    $entry = classifyingEntry();

    runClassification($entry);

    $entry->refresh();

    expect($judge->calls)->toBe(0)
        ->and($entry->status)->toBe(KnowledgeStatus::Pending->value)
        ->and($entry->importance_assessment_id)->toBeNull()
        ->and($entry->metadata)->not->toHaveKey('importance')
        ->and(ImportanceAssessment::query()->count())->toBe(0);
});

it('writes the verdict snapshot without touching unrelated metadata keys', function () {
    classifierMode(ImportanceClassifierMode::Shadow);
    spyJudge(importantJudgement());
    $entry = classifyingEntry(['source_path' => '/sessions/42.jsonl', 'condense_run_id' => 7]);

    runClassification($entry);

    $entry->refresh();

    expect($entry->metadata['source_path'])->toBe('/sessions/42.jsonl')
        ->and($entry->metadata['condense_run_id'])->toBe(7)
        ->and($entry->metadata['importance']['verdict'])->toBe(ImportanceVerdict::Important->value);
});

it('does nothing to an entry that has already left classifying', function (string $status) {
    classifierMode(ImportanceClassifierMode::Enforce);
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
    classifierMode(ImportanceClassifierMode::Enforce);
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
    classifierMode(ImportanceClassifierMode::Shadow);
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
    classifierMode(ImportanceClassifierMode::Shadow);
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
    classifierMode(ImportanceClassifierMode::Enforce);
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
        ->and($error['prompt_version'])->toBe((string) config('rag.importance.prompt_version'))
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

it('never logs the raw failure text of an unexpected exception', function () {
    classifierMode(ImportanceClassifierMode::Shadow);
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
    classifierMode(ImportanceClassifierMode::Enforce);
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
    classifierMode(ImportanceClassifierMode::Enforce);
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
    classifierMode(ImportanceClassifierMode::Shadow);
    $entry = classifyingEntry();

    (new ClassifyKnowledgeEntryJob((int) $entry->id))
        ->failed(ImportanceAssessmentInProgressException::make());

    $entry->refresh();

    expect($entry->status)->toBe(KnowledgeStatus::Pending->value)
        ->and($entry->metadata['importance']['classification_error']['code'])
        ->toBe(ImportanceAssessmentInProgressException::ERROR_CODE);
});

it('is idempotent in its terminal failure handler', function () {
    classifierMode(ImportanceClassifierMode::Shadow);
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

it('does nothing when the entry no longer exists', function () {
    classifierMode(ImportanceClassifierMode::Shadow);
    $judge = spyJudge(importantJudgement());

    app()->call([new ClassifyKnowledgeEntryJob(999_999), 'handle']);
    (new ClassifyKnowledgeEntryJob(999_999))->failed(new RuntimeException('gone'));

    expect($judge->calls)->toBe(0)
        ->and(ImportanceAssessment::query()->count())->toBe(0);
});

it('reuses a cached assessment for a repeated candidate', function () {
    classifierMode(ImportanceClassifierMode::Shadow);
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
        'prompt_version' => (string) config('rag.importance.prompt_version'),
        'rules_version' => DeterministicImportanceRules::VERSION,
        'status' => ImportanceAssessmentStatus::Running,
    ]);
}
