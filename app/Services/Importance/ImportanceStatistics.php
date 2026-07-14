<?php

namespace App\Services\Importance;

use App\Enums\ImportanceAssessmentStatus;
use App\Enums\ImportanceClassifierMode;
use App\Enums\ImportanceVerdict;
use App\Enums\KnowledgeStatus;
use App\Models\ImportanceAssessment;
use App\Models\KnowledgeEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The operational read model of the importance classifier: how many entries are
 * in flight, how many are stuck, what the shadow run has decided so far, and
 * whether the dedicated queue is being drained.
 *
 * Two disciplines hold everywhere in this class:
 *
 *  - **Aggregate in SQL.** `rag_status` calls this on every invocation; nothing
 *    it consumes may load an entry's `content` or an assessment's
 *    `normalized_candidate` into PHP just to count it. The single exception is
 *    {@see self::falseAutoApprovals()}, which recomputes a predicate per row and
 *    is therefore REPORT-ONLY: `rag:importance-report` is run by hand, and
 *    `rag_status` must never call it. It still reads only `id` and `metadata` —
 *    never an entry body.
 *  - **Shadow means shadow.** `metadata.importance.would_reject` is written in
 *    *every* mode (it mirrors "the computed verdict was not_important"), so an
 *    `enforce` rejection carries it too. Every shadow figure below therefore also
 *    filters on `metadata.importance.mode = 'shadow'`; without that filter the
 *    calibration numbers would quietly count enforce rejections as shadow
 *    evidence.
 */
final class ImportanceStatistics
{
    public function __construct(private readonly AutoApprovalPolicy $autoApproval) {}

    /**
     * Entries the classifier still owns, and those it has owned for too long —
     * a stale entry means the worker died, is not running, or never drained the
     * queue, and nobody is going to review that knowledge.
     *
     * @return array{total: int, stale: int, stale_after_minutes: int}
     */
    public function classifying(string $projectId): array
    {
        $staleAfterMinutes = $this->staleAfterMinutes();

        $inFlight = KnowledgeEntry::query()
            ->where('project_id', $projectId)
            ->where('status', KnowledgeStatus::Classifying->value);

        return [
            'total' => (clone $inFlight)->count(),
            'stale' => (clone $inFlight)
                ->where('updated_at', '<', now()->subMinutes($staleAfterMinutes))
                ->count(),
            'stale_after_minutes' => $staleAfterMinutes,
        ];
    }

    /**
     * Assessment outcomes for the project. `running` rows are deliberately not
     * reported: an in-flight assessment is already visible as a `classifying`
     * entry, and a `running` row abandoned by a dead worker is the classifying
     * staleness signal, not a separate one.
     *
     * @return array{succeeded: int, failed: int}
     */
    public function assessments(string $projectId): array
    {
        /** @var array<string, int> $counts */
        $counts = ImportanceAssessment::query()
            ->where('project_id', $projectId)
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        return [
            'succeeded' => $counts[ImportanceAssessmentStatus::Succeeded->value] ?? 0,
            'failed' => $counts[ImportanceAssessmentStatus::Failed->value] ?? 0,
        ];
    }

    /**
     * What the shadow run would have done: the whole point of `shadow` is that
     * these two numbers can be read before anything is enforced.
     *
     * @return array{would_keep: int, would_reject: int}
     */
    public function shadowVerdicts(string $projectId): array
    {
        return [
            'would_keep' => $this->shadowClassified($projectId)->where($this->wouldReject(false))->count(),
            'would_reject' => $this->shadowClassified($projectId)->where($this->wouldReject(true))->count(),
        ];
    }

    /**
     * Health of the dedicated `classification` queue.
     *
     * The `classification` connection is a second *connection* over the same
     * `jobs` table as the default one (only its `retry_after` differs), so the
     * counts must be scoped by queue name, never by table alone. When the
     * connection is not a database queue (or the tables are absent), the counts
     * are `null` rather than a misleading zero.
     *
     * @return array{name: string, pending: int|null, failed: int|null}
     */
    public function queue(): array
    {
        $connection = (string) config('rag.importance.queue_connection');
        $queue = (string) config('rag.importance.queue');
        $table = (string) config("queue.connections.{$connection}.table", 'jobs');

        $usable = config("queue.connections.{$connection}.driver") === 'database'
            && Schema::hasTable($table)
            && Schema::hasTable('failed_jobs');

        if (! $usable) {
            return ['name' => $queue, 'pending' => null, 'failed' => null];
        }

        return [
            'name' => $queue,
            'pending' => DB::table($table)->where('queue', $queue)->count(),
            'failed' => DB::table('failed_jobs')->where('queue', $queue)->count(),
        ];
    }

    /**
     * Entries the classifier approved on its own, with no human reading them.
     * Unlike every shadow figure, this is not filtered by mode: `auto_approved`
     * is only ever written true in `enforce`, and it is a record of what actually
     * happened, not of what would have.
     */
    public function autoApprovedCount(string $projectId): int
    {
        return KnowledgeEntry::query()
            ->where('project_id', $projectId)
            ->where($this->flagIs('auto_approved', true))
            ->count();
    }

    /**
     * How many shadow-classified entries the classifier would have approved with
     * nobody reading them. In `shadow` nothing is approved, so this is the size of
     * the risk `enforce` would take on.
     *
     * This reads the `would_approve` stamp, so it reports what the classifier
     * thought at the dial in force when it ran — fine for a status headline. Read
     * it beside `falseAutoApprovals()`, which recomputes eligibility at the dial
     * configured NOW and is the figure the readiness gate is allowed to trust.
     */
    public function shadowWouldApproveCount(string $projectId): int
    {
        return $this->shadowClassified($projectId)
            ->where($this->flagIs('would_approve', true))
            ->count();
    }

    /**
     * The calibration cross-tab: the shadow verdicts set against what a human
     * subsequently decided about the same entry.
     *
     * @return array{
     *     classified: int,
     *     reviewed: int,
     *     would_reject: int,
     *     approved: int,
     *     approved_would_reject: int,
     *     approved_would_approve: int,
     *     rejected: int,
     *     rejected_would_keep: int,
     * }
     */
    public function shadowReview(string $projectId): array
    {
        $reviewedStatuses = [KnowledgeStatus::Approved->value, KnowledgeStatus::Rejected->value];

        return [
            'classified' => $this->shadowClassified($projectId)->count(),
            'reviewed' => $this->shadowClassified($projectId)->whereIn('status', $reviewedStatuses)->count(),
            'would_reject' => $this->shadowClassified($projectId)->where($this->wouldReject(true))->count(),
            'approved' => $this->shadowClassified($projectId)
                ->where('status', KnowledgeStatus::Approved->value)
                ->count(),
            // A human kept it; the classifier would have thrown it away. This is
            // the false-reject rate the rollout gate is built on.
            'approved_would_reject' => $this->shadowClassified($projectId)
                ->where('status', KnowledgeStatus::Approved->value)
                ->where($this->wouldReject(true))
                ->count(),
            // A human approved it, and the classifier would have approved it
            // unread. This is the benefit auto-approval buys — reviews that need
            // not have happened — and it is reported, never gated: approving less
            // than it could is not a failure.
            'approved_would_approve' => $this->shadowClassified($projectId)
                ->where('status', KnowledgeStatus::Approved->value)
                ->where($this->flagIs('would_approve', true))
                ->count(),
            'rejected' => $this->shadowClassified($projectId)
                ->where('status', KnowledgeStatus::Rejected->value)
                ->count(),
            // A human threw it away; the classifier would have kept it. Cheap by
            // comparison — it only costs a review — so it is reported, not gated.
            'rejected_would_keep' => $this->shadowClassified($projectId)
                ->where('status', KnowledgeStatus::Rejected->value)
                ->where($this->wouldReject(false))
                ->count(),
        ];
    }

    /**
     * Gate 6, both of its figures, computed from the DATA rather than from a
     * stamp: for every shadow-classified entry a human threw away, the real
     * {@see AutoApprovalPolicy} is re-run over that entry's stored `final_score`
     * and `rules`, at the `auto_approve_threshold` CURRENTLY configured.
     *
     * It therefore answers the only question the gate is about — *"at the dial I
     * am about to enforce, how many entries a human rejected would this classifier
     * have approved with nobody reading them?"* — and answers it from evidence.
     *
     *  - `computable` (the anti-vacuity floor's population): rejections that carry
     *    both a `final_score` and a `rules` array, i.e. the ones whose eligibility
     *    CAN be decided. An entry classified before this feature has no `rules`
     *    key, so nothing can be said about it and it is excluded — it must not be
     *    able to satisfy the floor.
     *  - `false_approvals`: of those, the ones the policy says would be eligible
     *    right now.
     *
     * Reading `metadata.importance.would_approve` instead — the stamp
     * `ClassifyKnowledgeEntryJob::decide()` wrote at classify time — was the old
     * design, and it was wrong twice over. It threw away every rejection recorded
     * at another dial, including rejections whose stored score and rules are direct
     * evidence of a false approval at the dial being certified; and, because
     * eligibility is monotone decreasing in the dial, a `true` stamped at a LOWER
     * dial counted forever against a HIGHER one, which no amount of fresh clean
     * evidence could ever clear (rejected entries are terminal and never
     * re-classified). Recomputing has neither problem: it uses every row it can
     * read, it is recomputed on every run, it is correct across a dial moved in
     * either direction, and the two figures count the same population — so the
     * report can never print a self-contradictory "1 of 0".
     *
     * The policy is REUSED, never reimplemented: a copy of the predicate here would
     * only prove that the gate agrees with itself, and would keep agreeing with
     * itself while `AutoApprovalPolicy` had a bug. The verdict test that
     * `decide()` applies on top of the policy (`verdict === important`) is
     * deliberately NOT applied: it depends on the REJECT threshold, a different
     * dial from the one being certified, and omitting it can only ever make this
     * gate fail where it might have passed — the safe direction.
     *
     * REPORT-ONLY, and the one method in this class that is not a SQL aggregate:
     * it reads `id` and `metadata` per rejected row (never an entry body).
     * `rag:importance-report` is run by hand; `rag_status` runs on every MCP call
     * and must not call this.
     *
     * @return array{computable: int, false_approvals: int}
     */
    public function falseAutoApprovals(string $projectId, ?int $autoApproveThreshold): array
    {
        $computable = 0;
        $falseApprovals = 0;

        $rejections = $this->shadowClassified($projectId)
            ->where('status', KnowledgeStatus::Rejected->value)
            ->select(['id', 'metadata'])
            ->lazy();

        foreach ($rejections as $rejection) {
            $result = $this->storedResult($rejection);

            if ($result === null) {
                continue;
            }

            $computable++;

            if ($this->autoApproval->isEligible($result, $autoApproveThreshold)) {
                $falseApprovals++;
            }
        }

        return ['computable' => $computable, 'false_approvals' => $falseApprovals];
    }

    /**
     * Final scores of the shadow sample, bucketed in SQL into 20-point bands
     * (the last band is 80-100). Reading the distribution is how an operator
     * sees whether the configured threshold sits in a valley or cuts through
     * the middle of a cluster.
     *
     * @return array<string, int> bucket label => count, ascending
     */
    public function scoreDistribution(string $projectId): array
    {
        $bucket = "(least((metadata->'importance'->>'final_score')::int, 99) / 20) * 20";

        /** @var array<int, int> $counts */
        $counts = $this->shadowClassified($projectId)
            ->whereRaw("metadata->'importance'->>'final_score' IS NOT NULL")
            ->select(DB::raw("{$bucket} as bucket"), DB::raw('count(*) as aggregate'))
            ->groupBy(DB::raw($bucket))
            ->orderBy('bucket')
            ->pluck('aggregate', 'bucket')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        $distribution = [];

        foreach ($counts as $floor => $count) {
            $ceiling = (int) $floor === 80 ? 100 : (int) $floor + 19;
            $distribution["{$floor}-{$ceiling}"] = $count;
        }

        return $distribution;
    }

    /**
     * Entries this project's classifier has actually decided in `shadow`: a
     * computed verdict, in shadow mode. Entries that failed open carry a
     * `classification_error` and no verdict, and are not evidence of anything
     * the classifier would have done.
     *
     * @return Builder<KnowledgeEntry>
     */
    private function shadowClassified(string $projectId): Builder
    {
        return KnowledgeEntry::query()
            ->where('project_id', $projectId)
            ->whereRaw("metadata->'importance'->>'mode' = ?", [ImportanceClassifierMode::Shadow->value])
            ->whereRaw("metadata->'importance'->>'verdict' IS NOT NULL");
    }

    /**
     * The classification result as it was persisted on the entry, rebuilt so the
     * real policy can be re-run over it. `metadata.importance` already stores
     * everything {@see AutoApprovalPolicy::isEligible()} reads — the `final_score`
     * and the triggered `rules` with their adjustments — so nothing has to be
     * re-derived from the entry's content, and no model is ever called.
     *
     * Returns null when eligibility cannot be decided from what is stored: an
     * entry classified before this feature carries no `rules` key at all, a failed
     * classification carries no `final_score`, and hand-edited metadata may carry
     * neither in a readable shape. "Not computable" is the truthful answer for
     * those, and it keeps them out of the floor's population — they are not
     * evidence about the dial, and must not be able to satisfy the anti-vacuity
     * floor. Nothing here may throw: a malformed row must not take the report down.
     */
    private function storedResult(KnowledgeEntry $entry): ?ImportanceClassificationResult
    {
        $importance = $entry->metadata['importance'] ?? null;

        if (! is_array($importance)) {
            return null;
        }

        $finalScore = $importance['final_score'] ?? null;
        $storedRules = $importance['rules'] ?? null;

        if (! is_int($finalScore) || ! is_array($storedRules)) {
            return null;
        }

        /** @var list<array{id: string, adjustment: int, reason: string}> $rules */
        $rules = [];

        foreach ($storedRules as $rule) {
            if (! is_array($rule) || ! is_int($rule['adjustment'] ?? null)) {
                // A rule whose adjustment cannot be read cannot be weighed, so the
                // entry's eligibility cannot be decided at all.
                return null;
            }

            $rules[] = [
                'id' => (string) ($rule['id'] ?? ''),
                'adjustment' => $rule['adjustment'],
                'reason' => (string) ($rule['reason'] ?? ''),
            ];
        }

        $semanticScore = $importance['semantic_score'] ?? null;
        $verdict = $importance['verdict'] ?? null;

        return new ImportanceClassificationResult(
            semanticScore: is_int($semanticScore) ? $semanticScore : null,
            finalScore: $finalScore,
            verdict: is_string($verdict) ? ImportanceVerdict::tryFrom($verdict) : null,
            reasons: [],
            triggeredRules: $rules,
            cacheHit: false,
            model: (string) ($importance['model'] ?? ''),
            promptVersion: (string) ($importance['prompt_version'] ?? ''),
            rulesVersion: (string) ($importance['rules_version'] ?? ''),
        );
    }

    /**
     * @return callable(Builder<KnowledgeEntry>): void
     */
    private function wouldReject(bool $expected): callable
    {
        return $this->flagIs('would_reject', $expected);
    }

    /**
     * One of the classifier's JSON booleans under `metadata.importance`, compared
     * as text: `->>` renders a JSON boolean as 'true'/'false'.
     *
     * Deliberately a text comparison rather than a `::boolean` cast. An entry
     * classified before a flag existed simply has no key, so `->>` yields SQL NULL
     * and the row matches NEITHER `true` nor `false` — it is absent from the
     * population, which is the truthful answer. A cast would additionally throw on
     * any row whose metadata was hand-edited to a non-boolean, taking `rag_status`
     * down with it.
     *
     * `$flag` is restricted to the three known JSON keys via `match`, so the
     * `whereRaw` fragment can never carry anything but a literal this class wrote
     * itself, whatever a future caller passes in.
     *
     * @param  'auto_approved'|'would_approve'|'would_reject'  $flag
     * @return callable(Builder<KnowledgeEntry>): void
     */
    private function flagIs(string $flag, bool $expected): callable
    {
        $column = match ($flag) {
            'auto_approved' => "metadata->'importance'->>'auto_approved'",
            'would_approve' => "metadata->'importance'->>'would_approve'",
            'would_reject' => "metadata->'importance'->>'would_reject'",
        };

        return static function (Builder $query) use ($column, $expected): void {
            $query->whereRaw("{$column} = ?", [$expected ? 'true' : 'false']);
        };
    }

    private function staleAfterMinutes(): int
    {
        return (int) config('rag.importance.stale_after_minutes');
    }
}
