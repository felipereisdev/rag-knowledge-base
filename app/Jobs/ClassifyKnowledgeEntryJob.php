<?php

namespace App\Jobs;

use App\Enums\ImportanceClassifierMode;
use App\Enums\ImportanceVerdict;
use App\Enums\KnowledgeStatus;
use App\Models\Entity;
use App\Models\ImportanceAssessment;
use App\Models\ImportanceClassifierSetting;
use App\Models\KnowledgeEntry;
use App\Models\Relation;
use App\Services\Importance\AutoApprovalPolicy;
use App\Services\Importance\DeterministicImportanceRules;
use App\Services\Importance\HybridImportanceClassifier;
use App\Services\Importance\ImportanceAssessmentInProgressException;
use App\Services\Importance\ImportanceCandidate;
use App\Services\Importance\ImportanceCandidateNormalizer;
use App\Services\Importance\ImportanceClassificationResult;
use App\Services\Importance\ImportancePrompt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drives one `classifying` knowledge entry to its final status.
 *
 * Two properties govern everything here:
 *
 *  - **Fail open.** A *technical* failure (timeout, a `claude` binary that will
 *    not run, an unparseable response, an unexpected exception) never rejects an
 *    entry. It releases it to `pending` with a sanitized `classification_error`,
 *    so a human still sees it. Only a *computed* `not_important` verdict under
 *    `enforce` may reject.
 *  - **Idempotence.** Every write goes through one conditional transition that
 *    only fires `where status = classifying`. A double delivery, a retry, and the
 *    terminal `failed()` handler are therefore all harmless, and none of them can
 *    overwrite a decision a human made while the job was in flight.
 *
 * The single transient failure — another worker already owns the assessment for
 * this cache identity — is rethrown so the queue retries it with backoff. When
 * those retries run out, `failed()` fails the entry open like any other failure.
 */
class ClassifyKnowledgeEntryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const string UNEXPECTED_ERROR_CODE = 'unexpected_error';

    /**
     * Deliberately fixed and content-free: an unexpected exception's own message
     * may embed the candidate text, a raw stderr fragment, or an SQL statement,
     * and none of that may reach the entry's metadata or the logs.
     */
    public const string UNEXPECTED_ERROR_MESSAGE = 'The importance classifier failed unexpectedly.';

    /**
     * The fallback used when `RAG_IMPORTANCE_TIMEOUT` is unset. The single
     * literal for the model call's bound: `config/rag.php` and
     * `config/queue.php` (via {@see self::classificationRetryAfterSeconds()})
     * both read it from here so there is exactly one number to change, not two
     * that can drift apart.
     */
    public const int DEFAULT_MODEL_TIMEOUT_SECONDS = 90;

    /**
     * The worker must outlive the model call it is waiting on, otherwise the job
     * is killed mid-classification and leaves an orphaned assessment behind.
     */
    public const int TIMEOUT_MARGIN_SECONDS = 30;

    /**
     * How much the `classification` queue connection's `retry_after` must
     * exceed this job's own `$timeout` (see {@see self::classificationRetryAfterSeconds()}).
     */
    public const int RETRY_AFTER_MARGIN_SECONDS = 30;

    public int $tries = 3;

    public int $timeout;

    public readonly int $entryId;

    public function __construct(int|string $entryId)
    {
        $this->entryId = (int) $entryId;
        $this->timeout = (int) config('rag.importance.timeout') + self::TIMEOUT_MARGIN_SECONDS;
        $this->onConnection((string) config('rag.importance.queue_connection'));
        $this->onQueue((string) config('rag.importance.queue'));
    }

    /**
     * Sizes the `classification` queue connection's `retry_after` window
     * (`config/queue.php`), so a job still running near its own `$timeout`
     * cannot be silently re-reserved and handed to a second worker. The
     * required ordering:
     *
     *     Claude process timeout (RAG_IMPORTANCE_TIMEOUT, default
     *     {@see self::DEFAULT_MODEL_TIMEOUT_SECONDS})
     *   < this job's `$timeout`                        (+= TIMEOUT_MARGIN_SECONDS)
     *   < `classification` connection's `retry_after`   (+= RETRY_AFTER_MARGIN_SECONDS)
     *
     * Pure arithmetic on purpose — it takes the model timeout as a parameter
     * rather than reading it itself. `config/queue.php` is the caller and
     * loads (alphabetically) before `config/rag.php`, so `rag.importance.timeout`
     * is not yet in the config repository at that point; `config/queue.php`
     * reads `RAG_IMPORTANCE_TIMEOUT` from the environment directly (the same
     * var and default `config/rag.php` uses for the same setting) and passes
     * it in, so this method never needs `env()`/`config()` itself and the two
     * config files cannot define the model timeout differently.
     */
    public static function classificationRetryAfterSeconds(int $modelTimeoutSeconds): int
    {
        return $modelTimeoutSeconds + self::TIMEOUT_MARGIN_SECONDS + self::RETRY_AFTER_MARGIN_SECONDS;
    }

    /**
     * Contention is resolved by waiting: the worker that owns the assessment is
     * making a model call, which is bounded by the classifier's own timeout.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 60];
    }

    public function handle(
        HybridImportanceClassifier $classifier,
        ImportanceCandidateNormalizer $normalizer,
        AutoApprovalPolicy $autoApproval,
    ): void {
        $entry = KnowledgeEntry::query()->find($this->entryId);

        if ($entry === null) {
            Log::warning('ClassifyKnowledgeEntryJob: entry not found', ['entry_id' => $this->entryId]);

            return;
        }

        if ($entry->status !== KnowledgeStatus::Classifying->value) {
            Log::info('ClassifyKnowledgeEntryJob: entry already left classifying', [
                'entry_id' => $this->entryId,
                'status' => $entry->status,
            ]);

            return;
        }

        $mode = $this->mode();

        if ($mode === ImportanceClassifierMode::Off) {
            // The administrator turned the classifier off after this entry was
            // captured. It must not stay stuck in `classifying`, and there is no
            // verdict to record for it.
            $this->transition($entry, KnowledgeStatus::Pending, importance: null, assessmentId: null);

            Log::info('ClassifyKnowledgeEntryJob: released without classifying', [
                'entry_id' => $this->entryId,
                'mode' => $mode->value,
            ]);

            return;
        }

        $candidate = $this->candidate($entry);

        try {
            $result = $classifier->classify((string) $entry->project_id, $candidate);
        } catch (ImportanceAssessmentInProgressException $exception) {
            // The one transient failure: another worker owns this assessment.
            // Rethrow so the queue retries with backoff instead of failing open
            // on what is only a moment of contention.
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('ClassifyKnowledgeEntryJob: classification failed unexpectedly', [
                'entry_id' => $this->entryId,
                'project_id' => $entry->project_id,
                // The class only. The message may carry raw candidate text.
                'exception' => $exception::class,
            ]);

            $this->failOpen($entry, $mode, self::UNEXPECTED_ERROR_CODE, self::UNEXPECTED_ERROR_MESSAGE);

            return;
        }

        if ($result->errorCode !== null) {
            $this->failOpen($entry, $mode, $result->errorCode, (string) $result->errorMessage, $result);

            return;
        }

        $this->decide($entry, $mode, $result, $normalizer->normalize($candidate)->hash(), $autoApproval);
    }

    /**
     * Terminal recovery. Runs when the retries are exhausted, or when the worker
     * itself dies (timeout, killed process), so the entry cannot stay stuck in
     * `classifying`. The transition guard makes this safe to run late, twice, or
     * after the entry has already been decided.
     *
     * Laravel does not retry `failed()` itself, so this is wrapped end to end:
     * `mode()` and `failOpen()` both touch the database, and if either of those
     * throws (a dead connection, a deadlock) the entry would otherwise be
     * stranded in `classifying` forever — the one gap fail-open would have left
     * uncovered.
     */
    public function failed(?Throwable $exception): void
    {
        try {
            $entry = KnowledgeEntry::query()->find($this->entryId);

            if ($entry === null || $entry->status !== KnowledgeStatus::Classifying->value) {
                return;
            }

            // The contention exception is sanitized by construction; anything else
            // is replaced with a fixed message.
            [$code, $message] = $exception instanceof ImportanceAssessmentInProgressException
                ? [$exception->errorCode, $exception->getMessage()]
                : [self::UNEXPECTED_ERROR_CODE, self::UNEXPECTED_ERROR_MESSAGE];

            Log::error('ClassifyKnowledgeEntryJob: failing the entry open', [
                'entry_id' => $this->entryId,
                'error_code' => $code,
                'exception' => $exception === null ? null : $exception::class,
            ]);

            $this->failOpen($entry, $this->mode(), $code, $message);
        } catch (Throwable $recoveryException) {
            // Nothing left to retry this: log and give up. The entry stays
            // `classifying`, but that is a visible, alertable state rather than
            // a silent one, and it is exactly what would have happened anyway
            // had this catch not existed.
            Log::error('ClassifyKnowledgeEntryJob: terminal recovery itself failed; entry may be stranded in classifying', [
                'entry_id' => $this->entryId,
                'exception' => $recoveryException::class,
            ]);
        }
    }

    /**
     * Apply the computed verdict. This is the only path that may reject or
     * approve, and the status is chosen exactly once, here: `enforce` acts on a
     * `not_important` verdict by rejecting, and on an `important` AND *eligible*
     * verdict by approving — no human involved either way. `shadow` computes and
     * records both (`would_reject`, `would_approve`) but never acts: its status
     * is always `pending`. Eligibility itself is `AutoApprovalPolicy`'s call, not
     * this method's — it never re-derives that decision.
     */
    private function decide(
        KnowledgeEntry $entry,
        ImportanceClassifierMode $mode,
        ImportanceClassificationResult $result,
        string $candidateHash,
        AutoApprovalPolicy $autoApproval,
    ): void {
        $setting = ImportanceClassifierSetting::current();

        $wouldReject = $result->verdict === ImportanceVerdict::NotImportant;
        $wouldApprove = ! $wouldReject
            && $result->verdict === ImportanceVerdict::Important
            && $autoApproval->isEligible($result, $setting->auto_approve_threshold);

        $enforcing = $mode === ImportanceClassifierMode::Enforce;

        $status = match (true) {
            $wouldReject && $enforcing => KnowledgeStatus::Rejected,
            $wouldApprove && $enforcing => KnowledgeStatus::Approved,
            default => KnowledgeStatus::Pending,
        };

        $autoApproved = $status === KnowledgeStatus::Approved;

        $assessmentId = $this->assessmentId($entry, $result, $candidateHash);

        $applied = $this->transition($entry, $status, [
            'semantic_score' => $result->semanticScore,
            'final_score' => $result->finalScore,
            'verdict' => $result->verdict?->value,
            'mode' => $mode->value,
            'would_reject' => $wouldReject,
            'would_approve' => $wouldApprove,
            'auto_approved' => $autoApproved,
            'reasons' => $result->reasons,
            'rules' => $result->triggeredRules,
            'model' => $result->model,
            'prompt_version' => $result->promptVersion,
            'rules_version' => $result->rulesVersion,
            'candidate_hash' => $candidateHash,
            'cache_hit' => $result->cacheHit,
            'classified_at' => now()->toIso8601String(),
        ], $assessmentId);

        Log::info('ClassifyKnowledgeEntryJob: classified', [
            'entry_id' => $this->entryId,
            'project_id' => $entry->project_id,
            'mode' => $mode->value,
            'verdict' => $result->verdict?->value,
            'final_score' => $result->finalScore,
            'would_approve' => $wouldApprove,
            'auto_approved' => $autoApproved,
            'cache_hit' => $result->cacheHit,
            'assessment_id' => $assessmentId,
            'status' => $applied ? $status->value : $entry->fresh()?->status,
            'applied' => $applied,
        ]);
    }

    /**
     * Release the entry to a human with a sanitized account of what broke.
     *
     * The failed attempt is already recorded on the assessment row (and in the
     * log line above), so the entry carries only the code, the safe message, and
     * the versions the failure is attributable to — no assessment is associated,
     * because there is no usable verdict to attribute to it.
     */
    private function failOpen(
        KnowledgeEntry $entry,
        ImportanceClassifierMode $mode,
        string $code,
        string $message,
        ?ImportanceClassificationResult $result = null,
    ): void {
        $applied = $this->transition($entry, KnowledgeStatus::Pending, [
            'mode' => $mode->value,
            'classified_at' => now()->toIso8601String(),
            'classification_error' => [
                'code' => $code,
                'message' => $message,
                'model' => $result->model ?? (string) config('rag.importance.model'),
                // The class constants, never `config('rag.importance.*_version')`:
                // this is an audit record, and a `config:cache` snapshot taken
                // before a version bump would attribute the failure to the wrong
                // rules/prompt. See AppServiceProvider::register().
                'prompt_version' => $result->promptVersion ?? ImportancePrompt::VERSION,
                'rules_version' => $result->rulesVersion ?? DeterministicImportanceRules::VERSION,
            ],
        ], assessmentId: null);

        Log::warning('ClassifyKnowledgeEntryJob: failed open to pending', [
            'entry_id' => $this->entryId,
            'project_id' => $entry->project_id,
            'mode' => $mode->value,
            'error_code' => $code,
            'applied' => $applied,
        ]);
    }

    /**
     * The one write path, and the only place the entry's status changes.
     *
     * The row is re-read under a lock behind `where status = classifying`, so a
     * duplicate delivery, a retry, or a late `failed()` call finds nothing to do
     * and cannot overwrite a human's decision. The save goes through Eloquent on
     * purpose: the observer's indexing hook is what releases the entry into the
     * index once it becomes `pending`.
     *
     * @param  array<string, mixed>|null  $importance  merged into `metadata.importance`,
     *                                                 leaving every other metadata key untouched
     */
    private function transition(
        KnowledgeEntry $entry,
        KnowledgeStatus $status,
        ?array $importance,
        ?int $assessmentId,
    ): bool {
        return (bool) DB::transaction(function () use ($entry, $status, $importance, $assessmentId): bool {
            $locked = KnowledgeEntry::query()
                ->whereKey($entry->getKey())
                ->where('status', KnowledgeStatus::Classifying->value)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return false;
            }

            $locked->status = $status->value;

            if ($assessmentId !== null) {
                $locked->importance_assessment_id = $assessmentId;
            }

            if ($importance !== null) {
                // Merged, never replaced: the entry's own metadata (source path,
                // condense run, importer fields) must survive classification.
                $locked->metadata = array_merge($locked->metadata, ['importance' => $importance]);
            }

            $locked->save();

            return true;
        });
    }

    /**
     * The assessment the classifier just produced or reused, found by the cache
     * identity it was keyed on.
     */
    private function assessmentId(KnowledgeEntry $entry, ImportanceClassificationResult $result, string $candidateHash): ?int
    {
        $id = ImportanceAssessment::query()
            ->where([
                'project_id' => $entry->project_id,
                'candidate_hash' => $candidateHash,
                'model' => $result->model,
                'prompt_version' => $result->promptVersion,
                'rules_version' => $result->rulesVersion,
            ])
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function candidate(KnowledgeEntry $entry): ImportanceCandidate
    {
        /** @var list<string> $tags */
        $tags = $entry->tags()
            ->orderBy('name')
            ->pluck('name')
            ->map(static fn (mixed $name): string => (string) $name)
            ->values()
            ->all();

        /** @var list<array{name:string, type:string}> $entities */
        $entities = $entry->entities()
            ->orderBy('name')
            ->get()
            ->map(static fn (Entity $entity): array => [
                'name' => (string) $entity->name,
                'type' => (string) $entity->type,
            ])
            ->values()
            ->all();

        /** @var list<array{subject:string, predicate:string, object:string}> $relations */
        $relations = Relation::query()
            ->where('entry_id', $entry->getKey())
            ->with(['subject', 'object'])
            ->get()
            ->map(static fn (Relation $relation): array => [
                'subject' => (string) $relation->subject?->name,
                'predicate' => (string) $relation->predicate,
                'object' => (string) $relation->object?->name,
            ])
            ->values()
            ->all();

        return new ImportanceCandidate(
            title: (string) $entry->title,
            content: (string) $entry->content,
            category: (string) $entry->category,
            source: (string) $entry->source,
            tags: $tags,
            entities: $entities,
            relations: $relations,
        );
    }

    private function mode(): ImportanceClassifierMode
    {
        return ImportanceClassifierSetting::current()->mode;
    }
}
