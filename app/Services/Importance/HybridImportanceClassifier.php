<?php

namespace App\Services\Importance;

use App\Enums\ImportanceAssessmentStatus;
use App\Enums\ImportanceVerdict;
use App\Models\ImportanceAssessment;
use App\Models\ImportanceClassifierSetting;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Combines the semantic half (the model's criterion scores) with the
 * deterministic half (versioned rules) into one auditable verdict.
 *
 * Order of operations, and why:
 *
 *  1. Normalize the candidate — the canonical form is what we hash and cache.
 *  2. Evaluate the deterministic rules. They are cheap and pure, and a hard veto
 *     means we never pay for a model call at all.
 *  3. Acquire the assessment row for this cache identity
 *     (project + candidate hash + model + prompt version + rules version).
 *     A `succeeded` row is reused as-is; a `failed` row is reclaimed and retried;
 *     a fresh `running` row belongs to another worker, so we raise the transient
 *     `ImportanceAssessmentInProgressException` and let the caller back off; a
 *     `running` row abandoned by a crashed worker (older than `staleAfterMinutes`)
 *     is reclaimed and re-judged.
 *  4. Call the judge OUTSIDE any transaction. The row is already `running`, so no
 *     database lock is held across a process/network call.
 *  5. Sum the criteria, apply the rule adjustments, clamp to 0..100, and compare
 *     with the administrator's threshold. The model's own recommended verdict is
 *     never consulted here.
 *
 * The threshold is read from the `ImportanceClassifierSetting` singleton on every
 * call, which is precisely why it is NOT part of the cache identity: changing it
 * re-derives the verdict from the stored assessment without a new model call.
 */
final class HybridImportanceClassifier
{
    /** The `error_message` column is bounded; sanitized messages are short anyway. */
    private const int MAX_ERROR_MESSAGE_LENGTH = 480;

    private const string UNIQUE_VIOLATION = '23505';

    /**
     * A floor, not a suggestion: `0` (or a non-numeric env value, which casts
     * to `0`) would make the reclamation predicate `updated_at <= now()`, so
     * every `running` row is instantly "stale" and two workers can judge the
     * same candidate at once. The class owns this invariant itself rather
     * than trusting the caller (`AppServiceProvider`, wiring
     * `rag.importance.stale_after_minutes`) to have validated it.
     */
    private const int MIN_STALE_AFTER_MINUTES = 1;

    private readonly int $staleAfterMinutes;

    /**
     * @param  int  $staleAfterMinutes  How long a `running` assessment may go without a
     *                                  write before it is considered abandoned rather than owned.
     *                                  Floored to {@see self::MIN_STALE_AFTER_MINUTES}.
     */
    public function __construct(
        private readonly ImportanceCandidateNormalizer $normalizer,
        private readonly DeterministicImportanceRules $rules,
        private readonly SemanticImportanceJudge $judge,
        private readonly string $model,
        private readonly string $promptVersion,
        int $staleAfterMinutes = 15,
    ) {
        $this->staleAfterMinutes = max(self::MIN_STALE_AFTER_MINUTES, $staleAfterMinutes);
    }

    /**
     * @throws ImportanceAssessmentInProgressException when another worker owns this
     *                                                 assessment; the caller should retry with backoff.
     */
    public function classify(string $projectId, ImportanceCandidate $candidate): ImportanceClassificationResult
    {
        $normalized = $this->normalizer->normalize($candidate);
        $evaluation = $this->rules->evaluate($normalized);
        $threshold = $this->threshold();

        $identity = [
            'project_id' => $projectId,
            'candidate_hash' => $normalized->hash(),
            'model' => $this->model,
            'prompt_version' => $this->promptVersion,
            'rules_version' => $evaluation->rulesVersion,
        ];

        $assessment = $this->acquire($identity, $normalized);

        if ($assessment->status === ImportanceAssessmentStatus::Succeeded) {
            return $this->cachedResult($assessment, $evaluation, $threshold);
        }

        if ($evaluation->vetoed) {
            return $this->judged($assessment, $evaluation, semantic: null, threshold: $threshold, durationMs: 0);
        }

        $startedAt = hrtime(true);

        try {
            $semantic = $this->judge->assess($normalized);
        } catch (ImportanceClassificationException $exception) {
            return $this->failed($assessment, $evaluation, $exception, $this->elapsedMs($startedAt));
        }

        return $this->judged($assessment, $evaluation, $semantic, $threshold, $this->elapsedMs($startedAt));
    }

    /**
     * Return an assessment that is either already `succeeded` (reuse it) or that
     * this worker now owns in `running` (produce it).
     *
     * The only long-lived state here is the row itself: the transaction wraps the
     * insert alone, never the model call.
     *
     * @param  array<string, string>  $identity
     */
    private function acquire(array $identity, NormalizedImportanceCandidate $normalized): ImportanceAssessment
    {
        $existing = $this->find($identity);

        if ($existing !== null) {
            return $this->claim($existing);
        }

        try {
            return DB::transaction(fn (): ImportanceAssessment => ImportanceAssessment::query()->create([
                ...$identity,
                'normalized_candidate' => $normalized->data(),
                'status' => ImportanceAssessmentStatus::Running,
            ]));
        } catch (QueryException $exception) {
            // Only a unique-key race is recoverable. Anything else (a not-null
            // violation, a bad column value, a dead connection) is a real failure
            // and must surface instead of being mistaken for a lost race.
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $winner = $this->find($identity);

            if ($winner === null) {
                throw $exception;
            }

            return $this->claim($winner);
        }
    }

    /**
     * Reuse a finished assessment, take over a failed or abandoned one, or step
     * aside for the worker that currently owns it.
     *
     * A `running` row is only evidence of another worker while it is *fresh*. A
     * worker that dies mid-classification (killed, timed out, host restarted)
     * leaves its row `running` forever, and without reclamation every later job
     * for that cache identity would raise the transient exception, exhaust its
     * retries, fail open, and never classify that candidate again. So a `running`
     * row whose last write is older than `staleAfterMinutes` is taken over and
     * re-judged.
     */
    private function claim(ImportanceAssessment $assessment): ImportanceAssessment
    {
        if ($assessment->status === ImportanceAssessmentStatus::Succeeded) {
            return $assessment;
        }

        if ($assessment->status === ImportanceAssessmentStatus::Failed) {
            // A conditional update, so two workers cannot both retry the same
            // failed assessment: exactly one of them flips it back to `running`.
            $claimed = ImportanceAssessment::query()
                ->whereKey($assessment->getKey())
                ->where('status', ImportanceAssessmentStatus::Failed->value)
                ->update([
                    'status' => ImportanceAssessmentStatus::Running->value,
                    'error_code' => null,
                    'error_message' => null,
                    'updated_at' => now(),
                ]);

            $assessment->refresh();

            if ($claimed === 1 || $assessment->status === ImportanceAssessmentStatus::Succeeded) {
                return $assessment;
            }
        }

        if ($assessment->status === ImportanceAssessmentStatus::Running) {
            // The same conditional-update discipline as above, keyed on the row's
            // current state: the takeover bumps `updated_at`, so a second worker
            // racing for the same stale row matches no row and steps aside.
            $reclaimed = ImportanceAssessment::query()
                ->whereKey($assessment->getKey())
                ->where('status', ImportanceAssessmentStatus::Running->value)
                ->where('updated_at', '<=', now()->subMinutes($this->staleAfterMinutes))
                ->update([
                    'error_code' => null,
                    'error_message' => null,
                    'updated_at' => now(),
                ]);

            $assessment->refresh();

            if ($reclaimed === 1 || $assessment->status === ImportanceAssessmentStatus::Succeeded) {
                return $assessment;
            }
        }

        throw ImportanceAssessmentInProgressException::make();
    }

    /**
     * Finish an assessment we own: score it, persist it, and answer with it.
     *
     * `$semantic` is null on the hard-veto path, where the score is forced to zero
     * without ever consulting the judge.
     */
    private function judged(
        ImportanceAssessment $assessment,
        RuleEvaluation $evaluation,
        ?SemanticImportanceAssessment $semantic,
        int $threshold,
        int $durationMs,
    ): ImportanceClassificationResult {
        $semanticScore = $semantic?->semanticScore;
        $reasons = $semantic?->reasons;
        $finalScore = $evaluation->apply($semanticScore ?? 0);
        $verdict = $this->verdict($finalScore, $threshold, $evaluation->vetoed);

        $assessment->update([
            'status' => ImportanceAssessmentStatus::Succeeded,
            'durability_score' => $semantic?->durability,
            'actionability_score' => $semantic?->actionability,
            'specificity_score' => $semantic?->specificity,
            'non_obviousness_score' => $semantic?->nonObviousness,
            'future_value_score' => $semantic?->futureValue,
            'semantic_score' => $semanticScore,
            'final_score' => $finalScore,
            'verdict' => $verdict,
            'reasons' => $reasons ?? [],
            'rules' => $evaluation->triggeredRules,
            'duration_ms' => $durationMs,
            'error_code' => null,
            'error_message' => null,
        ]);

        return new ImportanceClassificationResult(
            semanticScore: $semanticScore,
            finalScore: $finalScore,
            verdict: $verdict,
            reasons: $reasons ?? [],
            triggeredRules: $evaluation->triggeredRules,
            cacheHit: false,
            model: $assessment->model,
            promptVersion: $assessment->prompt_version,
            rulesVersion: $evaluation->rulesVersion,
            recommendedVerdict: $semantic?->recommendedVerdict,
        );
    }

    private function failed(
        ImportanceAssessment $assessment,
        RuleEvaluation $evaluation,
        ImportanceClassificationException $exception,
        int $durationMs,
    ): ImportanceClassificationResult {
        $message = Str::limit($exception->getMessage(), self::MAX_ERROR_MESSAGE_LENGTH);

        $assessment->update([
            'status' => ImportanceAssessmentStatus::Failed,
            'rules' => $evaluation->triggeredRules,
            'duration_ms' => $durationMs,
            'error_code' => $exception->errorCode,
            'error_message' => $message,
        ]);

        return new ImportanceClassificationResult(
            semanticScore: null,
            finalScore: null,
            verdict: null,
            reasons: [],
            triggeredRules: $evaluation->triggeredRules,
            cacheHit: false,
            model: $assessment->model,
            promptVersion: $assessment->prompt_version,
            rulesVersion: $evaluation->rulesVersion,
            errorCode: $exception->errorCode,
            errorMessage: $message,
        );
    }

    /**
     * Rebuild the decision from a stored assessment. The scores and rules are
     * fixed by the cache identity, but the verdict is re-derived, because the
     * threshold is an administrator setting that may have moved since.
     *
     * The rules are re-evaluated on every call (they are pure and cheap, and the
     * rules version is part of the cache identity, so they cannot disagree with
     * the stored ones) — which is what keeps a hard veto binding even here.
     *
     * The stored row is deliberately NOT re-stamped with the re-derived verdict.
     * An assessment is shared by every entry with the same cache identity, and
     * the threshold is not part of that identity, so re-stamping would rewrite
     * the audit record of entries decided earlier under a different threshold —
     * an entry rejected at 70 would end up pointing at a row reading `important`
     * because an unrelated later capture was decided at 60. `assessment.verdict`
     * therefore records the verdict as of the FIRST computation; the verdict that
     * actually applied to an entry lives in that entry's `metadata.importance`,
     * which is written per entry and never rewritten.
     */
    private function cachedResult(
        ImportanceAssessment $assessment,
        RuleEvaluation $evaluation,
        int $threshold,
    ): ImportanceClassificationResult {
        $finalScore = (int) $assessment->final_score;
        $verdict = $this->verdict($finalScore, $threshold, $evaluation->vetoed);

        return new ImportanceClassificationResult(
            semanticScore: $assessment->semantic_score,
            finalScore: $finalScore,
            verdict: $verdict,
            reasons: $assessment->reasons,
            triggeredRules: $assessment->rules,
            cacheHit: true,
            model: $assessment->model,
            promptVersion: $assessment->prompt_version,
            rulesVersion: $assessment->rules_version,
        );
    }

    /**
     * @param  array<string, string>  $identity
     */
    private function find(array $identity): ?ImportanceAssessment
    {
        return ImportanceAssessment::query()->where($identity)->first();
    }

    /**
     * A hard veto is unconditional: it does not merely score zero, it refuses the
     * candidate. Otherwise an administrator who lowers the threshold to zero would
     * let empty and placeholder content into the approval queue.
     */
    private function verdict(int $finalScore, int $threshold, bool $vetoed): ImportanceVerdict
    {
        if ($vetoed) {
            return ImportanceVerdict::NotImportant;
        }

        return $finalScore >= $threshold
            ? ImportanceVerdict::Important
            : ImportanceVerdict::NotImportant;
    }

    private function threshold(): int
    {
        $setting = ImportanceClassifierSetting::query()->find(1) ?? new ImportanceClassifierSetting;

        return (int) $setting->threshold;
    }

    /**
     * Exactly PostgreSQL's SQLSTATE 23505 (unique violation) and nothing else.
     *
     * `UniqueConstraintViolationException` is the framework's own typed wrapper
     * for that very SQLSTATE (see `PostgresConnection::isUniqueConstraintError`);
     * the raw code is checked too so the guard does not depend on that wrapping.
     */
    private function isUniqueViolation(QueryException $exception): bool
    {
        return $exception instanceof UniqueConstraintViolationException
            || $exception->getCode() === self::UNIQUE_VIOLATION;
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
