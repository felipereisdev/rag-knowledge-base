<?php

namespace App\Console\Commands;

use App\Models\ImportanceClassifierSetting;
use App\Models\Project;
use App\Services\Importance\DeterministicImportanceRules;
use App\Services\Importance\ImportanceCandidate;
use App\Services\Importance\ImportanceCandidateNormalizer;
use App\Services\Importance\ImportanceStatistics;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use JsonException;

/**
 * The calibration report an operator reads before deciding whether `enforce` is
 * safe for a project.
 *
 * It **reports readiness; it never acts on it.** Turning the classifier on is a
 * deliberate human act performed in Martis → Importance Classifier, because an
 * automatic rejection is invisible to whoever wrote the knowledge. Nothing in
 * this command writes to `importance_classifier_settings`.
 *
 * Readiness is the conjunction of the seven approved rollout gates — one failing
 * gate is enough to keep the classifier in shadow.
 *
 * Five of them guard the REJECT path: they ask whether the classifier throws away
 * knowledge a human would have kept. Two guard the APPROVE path, and they are not
 * the mirror image of the other five, because the two errors do not cost the same.
 * A false reject costs one click in a review queue. A false auto-approve puts junk
 * — or an injected instruction — into the base with nobody reading it, and search
 * then serves it to agents as trusted project knowledge. That asymmetry is why the
 * approve-path gates tolerate zero, and why they carry a floor: a clean record over
 * a sample too small to have caught anything is not evidence of safety.
 *
 * When auto-approval is off (`auto_approve_threshold` is null) nothing is approved
 * unread, so those two gates have nothing to validate and do not block. READY means
 * "everything that is switched on has been validated", not "every gate ran".
 */
class RagImportanceReportCommand extends Command
{
    /** At least this many classified entries must have been reviewed by a human. */
    public const int DEFAULT_MIN_SAMPLE = 50;

    /** No reviewed must-keep fixture may be rejected by the rules. */
    public const int MAX_MUST_KEEP_FALSE_REJECTS = 0;

    /** At most this share of the entries a human approved may be marked `would_reject`. */
    public const float MAX_APPROVED_WOULD_REJECT_RATE = 0.05;

    /** The classifier must keep at least this share of entries out of the review queue. */
    public const float MIN_QUEUE_REDUCTION_RATE = 0.30;

    /** No entry may be stranded in `classifying`. */
    public const int MAX_STALE_CLASSIFYING = 0;

    /**
     * The anti-vacuity floor under the false-auto-approval gate. "Zero false
     * approvals among zero rejections" is not a clean record, it is no record —
     * and the same vacuous pass was already found once in the false-reject gate,
     * which read `approved === 0` as a flawless 0.0%. Auto-approval may only be
     * certified against a rejected sample big enough for a mistake to have shown
     * up in it.
     */
    public const int MIN_REJECTED_SAMPLE = 10;

    /**
     * Zero tolerance, unlike the 5% the false-REJECT gate allows. One entry a
     * human threw away that the classifier would have approved unread is one
     * piece of junk it would have published to every agent that searches this
     * base, silently. There is no acceptable rate of that.
     */
    public const int MAX_FALSE_AUTO_APPROVALS = 0;

    /** No must-reject fixture may satisfy the deterministic half of eligibility. */
    public const int MAX_MUST_REJECT_ELIGIBLE = 0;

    protected $signature = 'rag:importance-report
        {--project= : Project ID (defaults to slugified cwd)}
        {--min-sample='.self::DEFAULT_MIN_SAMPLE.' : Minimum number of classified entries a human has since reviewed}';

    public function __construct()
    {
        parent::__construct();
        $this->setDescription(__('importance.report.description'));
    }

    public function handle(
        ImportanceStatistics $statistics,
        DeterministicImportanceRules $rules,
        ImportanceCandidateNormalizer $normalizer,
    ): int {
        $projectId = $this->resolveProjectId();
        $project = Project::find($projectId);

        if (! $project) {
            $this->error(__('importance.report.project_missing', ['project' => $projectId]));

            return self::FAILURE;
        }

        $minimumSample = $this->resolveMinimumSample();

        if ($minimumSample === null) {
            return self::FAILURE;
        }

        $setting = ImportanceClassifierSetting::current();

        // The dial that decides whether the two approve-path gates have anything
        // to say. Read once, never written: this command reports readiness and
        // never acts on it.
        $autoApprovalEnabled = $setting->auto_approve_threshold !== null;

        $review = $statistics->shadowReview($projectId);
        // Gate 6's two figures, recomputed from the stored `final_score` and
        // `rules` of every human-rejected shadow entry, at the dial in force —
        // never read off the `would_approve` stamp the classifier wrote against
        // whatever the dial happened to be that day. See
        // ImportanceStatistics::falseAutoApprovals(). Per-row, and affordable
        // precisely because this command is run by hand; `rag_status` never calls it.
        // Skipped entirely when the dial is off: gates 6 and 7 do not run, and the
        // per-row pass would be work nothing gates on.
        $falseApprovals = $autoApprovalEnabled
            ? $statistics->falseAutoApprovals($projectId, $setting->auto_approve_threshold)
            : ['computable' => 0, 'false_approvals' => 0];
        $classifying = $statistics->classifying($projectId);
        $mustKeep = $this->mustKeepFalseRejects($rules, $normalizer);
        $mustRejectEligible = $this->mustRejectEligible($rules, $normalizer);

        $reductionRate = $review['classified'] > 0
            ? $review['would_reject'] / $review['classified']
            : 0.0;
        $falseRejectRate = $review['approved'] > 0
            ? $review['approved_would_reject'] / $review['approved']
            : 0.0;

        // The auto-approve dial is printed, not only the reject threshold: this
        // report is the document an operator reads to decide whether to enforce,
        // and two of its seven gates are about the dial. It also names the value
        // the false-auto-approval floor counts evidence against, so "0 of 0" can
        // be read for what it is.
        $this->line(__('importance.report.heading', [
            'project' => $projectId,
            'mode' => $setting->mode->value,
            'threshold' => $setting->threshold,
            'auto_approve' => $autoApprovalEnabled
                ? (string) $setting->auto_approve_threshold
                : __('importance.report.auto_approve_off'),
        ]));
        $this->newLine();

        $this->line(__('importance.report.sample', [
            'reviewed' => $review['reviewed'],
            'minimum' => $minimumSample,
        ]));
        $this->line(__('importance.report.reduction', [
            'rate' => $this->percent($reductionRate),
            'rejected' => $review['would_reject'],
            'classified' => $review['classified'],
        ]));
        $this->line(__('importance.report.approved_would_reject', [
            'count' => $review['approved_would_reject'],
            'approved' => $review['approved'],
            'rate' => $this->percent($falseRejectRate),
        ]));
        $this->line(__('importance.report.rejected_would_keep', [
            'count' => $review['rejected_would_keep'],
            'rejected' => $review['rejected'],
        ]));
        // Its own wording, not the `rejected_would_keep` line's: the two sit next to
        // each other but count different populations. This one's denominator is only
        // the rejections whose auto-approval eligibility can be RECOMPUTED — the ones
        // that stored both a `final_score` and their `rules` — so an operator who
        // reads "15 of 15" above and "0 of 0" here is told why the population shrank
        // instead of being left to guess.
        $this->line(__('importance.report.rejected_would_approve', [
            'count' => $falseApprovals['false_approvals'],
            'classified' => $falseApprovals['computable'],
        ]));
        // A benefit measure, not a gate: the reviews auto-approval would have
        // saved. Approving fewer entries than it could is never unsafe, so nothing
        // is ever blocked on this number.
        $this->line(__('importance.report.review_reduction', [
            'count' => $review['approved_would_approve'],
            'total' => $review['approved'],
        ]));
        $this->line($mustRejectEligible === null
            ? __('importance.report.must_reject_unavailable', ['path' => $this->mustRejectCorpusPath()])
            : __('importance.report.must_reject', [
                'count' => $mustRejectEligible['eligible'],
                'total' => $mustRejectEligible['checked'],
            ]));
        $this->line(__('importance.report.stale', [
            'count' => $classifying['stale'],
            'minutes' => $classifying['stale_after_minutes'],
        ]));
        $this->line($mustKeep === null
            ? __('importance.report.must_keep_unavailable', ['path' => $this->mustKeepCorpusPath()])
            : __('importance.report.must_keep', [
                'count' => $mustKeep['false_rejects'],
                'total' => $mustKeep['checked'],
            ]));

        $this->newLine();
        $this->scoreDistribution($statistics, $projectId);

        $gates = [
            'sample' => [
                'requirement' => ">= {$minimumSample}",
                'actual' => (string) $review['reviewed'],
                'passes' => $review['reviewed'] >= $minimumSample,
            ],
            'must_keep' => [
                'requirement' => '= '.self::MAX_MUST_KEEP_FALSE_REJECTS,
                'actual' => $mustKeep === null
                    ? __('importance.report.corpus_unavailable')
                    : (string) $mustKeep['false_rejects'],
                // An unreadable corpus is not a pass: the gate cannot be shown to hold.
                'passes' => $mustKeep !== null
                    && $mustKeep['false_rejects'] <= self::MAX_MUST_KEEP_FALSE_REJECTS,
            ],
            'false_rejects' => [
                'requirement' => '<= '.$this->percent(self::MAX_APPROVED_WOULD_REJECT_RATE).'%',
                'actual' => $this->percent($falseRejectRate).'%',
                // Zero approved entries is zero evidence, not a passing rate: a
                // corpus that is all rejections must never read as a clean 0.0%.
                'passes' => $review['approved'] > 0
                    && $falseRejectRate <= self::MAX_APPROVED_WOULD_REJECT_RATE,
            ],
            'reduction' => [
                'requirement' => '>= '.$this->percent(self::MIN_QUEUE_REDUCTION_RATE).'%',
                'actual' => $this->percent($reductionRate).'%',
                'passes' => $reductionRate >= self::MIN_QUEUE_REDUCTION_RATE,
            ],
            'stale' => [
                'requirement' => '= '.self::MAX_STALE_CLASSIFYING,
                'actual' => (string) $classifying['stale'],
                'passes' => $classifying['stale'] <= self::MAX_STALE_CLASSIFYING,
            ],
            // Gate 6. Zero false auto-approvals, over a rejected sample big enough
            // for one to have surfaced. Both halves are required: without the
            // floor, a project that has rejected nothing certifies auto-approval
            // on no evidence at all.
            //
            // Both figures are RECOMPUTED by re-running `AutoApprovalPolicy` over
            // each rejection's stored `final_score` and `rules` at the dial in
            // force, so they answer "at the dial I am about to enforce, how many
            // entries a human rejected would this classifier have approved unread?"
            // from the data — not from the `would_approve` stamp, which recorded an
            // answer to that question about whatever the dial was on the day the
            // entry was classified. The floor's population is therefore every
            // rejection whose eligibility CAN be decided; an entry that predates the
            // feature stores no `rules` and says nothing about anything, so it
            // cannot satisfy the floor. See ImportanceStatistics::falseAutoApprovals().
            'false_auto_approvals' => [
                'requirement' => '= '.self::MAX_FALSE_AUTO_APPROVALS
                    .' ('.__('importance.report.min_rejected', ['count' => self::MIN_REJECTED_SAMPLE]).')',
                'actual' => $autoApprovalEnabled
                    ? $falseApprovals['false_approvals'].' / '.$falseApprovals['computable']
                    : __('importance.report.auto_approval_disabled'),
                'passes' => ! $autoApprovalEnabled || (
                    $falseApprovals['computable'] >= self::MIN_REJECTED_SAMPLE
                    && $falseApprovals['false_approvals'] <= self::MAX_FALSE_AUTO_APPROVALS
                ),
            ],
            // Gate 7. The model-independent half of the injection defence: no
            // fixture a reviewer judged worthless may satisfy the DETERMINISTIC
            // half of eligibility. An unreadable corpus proves nothing, so it
            // fails — it can never read as a pass.
            'must_reject' => [
                'requirement' => '= '.self::MAX_MUST_REJECT_ELIGIBLE,
                'actual' => match (true) {
                    ! $autoApprovalEnabled => __('importance.report.auto_approval_disabled'),
                    $mustRejectEligible === null => __('importance.report.corpus_unavailable'),
                    default => (string) $mustRejectEligible['eligible'],
                },
                'passes' => ! $autoApprovalEnabled || (
                    $mustRejectEligible !== null
                    && $mustRejectEligible['eligible'] <= self::MAX_MUST_REJECT_ELIGIBLE
                ),
            ],
        ];

        $this->newLine();
        $this->table(
            [
                __('importance.report.gate'),
                __('importance.report.requirement'),
                __('importance.report.actual'),
                __('importance.report.result'),
            ],
            array_map(
                fn (string $gate): array => [
                    __("importance.report.gates.{$gate}"),
                    $gates[$gate]['requirement'],
                    $gates[$gate]['actual'],
                    $gates[$gate]['passes']
                        ? __('importance.report.pass')
                        : __('importance.report.fail'),
                ],
                array_keys($gates),
            ),
        );

        $failed = count(array_filter($gates, static fn (array $gate): bool => ! $gate['passes']));

        $this->newLine();

        if ($failed === 0) {
            $this->info(__('importance.report.ready'));
        } else {
            $this->warn(__('importance.report.not_ready', [
                'count' => $failed,
                'total' => count($gates),
            ]));
        }

        // Said out loud because the whole point of the gates is that a human,
        // not this command, decides to enforce.
        $this->line(__('importance.report.never_changes_mode'));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function scoreDistribution(ImportanceStatistics $statistics, string $projectId): void
    {
        $distribution = $statistics->scoreDistribution($projectId);

        if ($distribution === []) {
            $this->line(__('importance.report.no_sample'));

            return;
        }

        $this->line(__('importance.report.distribution'));
        $this->table(
            [__('importance.report.bucket'), __('importance.report.entries')],
            array_map(
                static fn (string $bucket, int $count): array => [$bucket, $count],
                array_keys($distribution),
                array_values($distribution),
            ),
        );
    }

    /**
     * Re-runs the must-keep corpus — the reviewed knowledge that must survive the
     * classifier — through the deterministic rules at their current version. A
     * hard veto there is a false reject: it destroys knowledge a human already
     * confirmed is worth keeping, and no queue saving buys that back.
     *
     * Only the rules are exercised (a veto is what actually rejects without ever
     * consulting the judge), so this makes no model call and costs nothing.
     *
     * @return array{checked: int, false_rejects: int}|null null when the corpus is unreadable
     */
    private function mustKeepFalseRejects(
        DeterministicImportanceRules $rules,
        ImportanceCandidateNormalizer $normalizer,
    ): ?array {
        $fixtures = $this->corpusFixtures($this->mustKeepCorpusPath());

        if ($fixtures === null) {
            return null;
        }

        $falseRejects = 0;

        foreach ($fixtures as $fixture) {
            $evaluation = $rules->evaluate($normalizer->normalize($this->candidate($fixture['candidate'])));

            if ($evaluation->vetoed) {
                $falseRejects++;
            }
        }

        return ['checked' => count($fixtures), 'false_rejects' => $falseRejects];
    }

    /**
     * The mirror of the must-keep check, for the mirror risk. It re-runs the
     * must-reject corpus — knowledge a reviewer judged worthless — through the
     * deterministic rules and counts the fixtures that satisfy the DETERMINISTIC
     * half of auto-approval eligibility: at least one positive signal and not one
     * penalty (AutoApprovalPolicy's rule test, exactly). A fixture that does is
     * one bad model score away from being published to every agent that searches
     * this base, with nobody reading it.
     *
     * Deliberately model-INDEPENDENT, and the honest scope of this gate has to be
     * stated: it never calls Claude. The corpus's semantic scores are a reviewer's
     * estimates handed to a fake judge, so they cannot prove what the real model
     * would score, and a gate built on them would be measuring the reviewer, not
     * the classifier. The deterministic signals are the half an injection cannot
     * argue with — they are regex, not inference — so they are the half that
     * carries the defence, and they are what this pins. It also makes the gate
     * free to run, which is why `rag:importance-report` can afford it.
     *
     * @return array{checked: int, eligible: int}|null null when the corpus is unreadable
     */
    private function mustRejectEligible(
        DeterministicImportanceRules $rules,
        ImportanceCandidateNormalizer $normalizer,
    ): ?array {
        $fixtures = $this->corpusFixtures($this->mustRejectCorpusPath());

        if ($fixtures === null) {
            return null;
        }

        $eligible = 0;

        foreach ($fixtures as $fixture) {
            $evaluation = $rules->evaluate($normalizer->normalize($this->candidate($fixture['candidate'])));

            $positives = 0;
            $penalties = 0;

            foreach ($evaluation->triggeredRules as $rule) {
                if ($rule['adjustment'] > 0) {
                    $positives++;
                }

                // A veto is just a very large penalty, so it is caught here too.
                if ($rule['adjustment'] < 0) {
                    $penalties++;
                }
            }

            if ($positives >= 1 && $penalties === 0) {
                $eligible++;
            }
        }

        return ['checked' => count($fixtures), 'eligible' => $eligible];
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function candidate(array $candidate): ImportanceCandidate
    {
        /** @var array{title?: string, content?: string, category?: string, source?: string, tags?: list<string>, entities?: list<array{name: string, type: string}>, relations?: list<array{subject: string, predicate: string, object: string}>} $candidate */
        return new ImportanceCandidate(
            title: (string) ($candidate['title'] ?? ''),
            content: (string) ($candidate['content'] ?? ''),
            category: (string) ($candidate['category'] ?? 'insight'),
            source: (string) ($candidate['source'] ?? 'manual'),
            tags: $candidate['tags'] ?? [],
            entities: $candidate['entities'] ?? [],
            relations: $candidate['relations'] ?? [],
        );
    }

    /**
     * @return list<array{candidate: array<string, mixed>}>|null null when the corpus is unreadable
     */
    private function corpusFixtures(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        try {
            /** @var array{fixtures?: list<array{candidate: array<string, mixed>}>} $corpus */
            $corpus = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return $corpus['fixtures'] ?? [];
    }

    private function mustKeepCorpusPath(): string
    {
        return (string) config('rag.importance.must_keep_corpus_path');
    }

    private function mustRejectCorpusPath(): string
    {
        return (string) config('rag.importance.must_reject_corpus_path');
    }

    private function percent(float $rate): string
    {
        return number_format($rate * 100, 1);
    }

    /**
     * Rejects a typo (`--min-sample=0`, `--min-sample=abc`) instead of silently
     * coercing it to 1, which would quietly weaken the primary sample gate.
     */
    private function resolveMinimumSample(): ?int
    {
        $raw = (string) $this->option('min-sample');

        if (! ctype_digit($raw) || (int) $raw < 1) {
            $this->error(__('importance.report.invalid_min_sample', ['value' => $raw]));

            return null;
        }

        return (int) $raw;
    }

    private function resolveProjectId(): string
    {
        $pid = $this->option('project');

        if ($pid !== null && $pid !== '') {
            return (string) $pid;
        }

        $cwd = (string) getcwd();

        return Str::slug(basename($cwd)) ?: 'project';
    }
}
