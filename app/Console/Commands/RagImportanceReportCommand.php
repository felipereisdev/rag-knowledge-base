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
 * Readiness is the conjunction of the five approved rollout gates — one failing
 * gate is enough to keep the classifier in shadow.
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

    protected $signature = 'rag:importance-report
        {--project= : Project ID (defaults to slugified cwd)}
        {--min-sample=50 : Minimum number of classified entries a human has since reviewed}';

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

        $minimumSample = max(1, (int) $this->option('min-sample'));
        $setting = ImportanceClassifierSetting::current();

        $review = $statistics->shadowReview($projectId);
        $classifying = $statistics->classifying($projectId);
        $mustKeep = $this->mustKeepFalseRejects($rules, $normalizer);

        $reductionRate = $review['classified'] > 0
            ? $review['would_reject'] / $review['classified']
            : 0.0;
        $falseRejectRate = $review['approved'] > 0
            ? $review['approved_would_reject'] / $review['approved']
            : 0.0;

        $this->line(__('importance.report.heading', [
            'project' => $projectId,
            'mode' => $setting->mode->value,
            'threshold' => $setting->threshold,
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
                'actual' => $mustKeep === null ? '—' : (string) $mustKeep['false_rejects'],
                // An unreadable corpus is not a pass: the gate cannot be shown to hold.
                'passes' => $mustKeep !== null
                    && $mustKeep['false_rejects'] <= self::MAX_MUST_KEEP_FALSE_REJECTS,
            ],
            'false_rejects' => [
                'requirement' => '<= '.$this->percent(self::MAX_APPROVED_WOULD_REJECT_RATE).'%',
                'actual' => $this->percent($falseRejectRate).'%',
                'passes' => $falseRejectRate <= self::MAX_APPROVED_WOULD_REJECT_RATE,
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
        $path = $this->mustKeepCorpusPath();

        if (! is_file($path)) {
            return null;
        }

        try {
            /** @var array{fixtures?: list<array{candidate: array<string, mixed>}>} $corpus */
            $corpus = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $fixtures = $corpus['fixtures'] ?? [];
        $falseRejects = 0;

        foreach ($fixtures as $fixture) {
            /** @var array{title?: string, content?: string, category?: string, source?: string, tags?: list<string>, entities?: list<array{name: string, type: string}>, relations?: list<array{subject: string, predicate: string, object: string}>} $candidate */
            $candidate = $fixture['candidate'];

            $evaluation = $rules->evaluate($normalizer->normalize(new ImportanceCandidate(
                title: (string) ($candidate['title'] ?? ''),
                content: (string) ($candidate['content'] ?? ''),
                category: (string) ($candidate['category'] ?? 'insight'),
                source: (string) ($candidate['source'] ?? 'manual'),
                tags: $candidate['tags'] ?? [],
                entities: $candidate['entities'] ?? [],
                relations: $candidate['relations'] ?? [],
            )));

            if ($evaluation->vetoed) {
                $falseRejects++;
            }
        }

        return ['checked' => count($fixtures), 'false_rejects' => $falseRejects];
    }

    private function mustKeepCorpusPath(): string
    {
        return base_path('tests/Fixtures/importance/must-keep.json');
    }

    private function percent(float $rate): string
    {
        return number_format($rate * 100, 1);
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
