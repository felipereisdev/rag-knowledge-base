<?php

use App\Models\ImportanceClassifierSetting;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // The report reads rows; it never indexes. Faking the queue keeps the
    // observer's IndexEntryJob (and the embedder) out of these tests.
    Queue::fake();

    Project::create([
        'id' => 'report-project',
        'name' => 'Report Project',
        'root_path' => '/tmp/report-project',
        'language' => 'en',
    ]);
});

/**
 * A rule set with a positive signal and no penalty: `AutoApprovalPolicy` approves
 * it at any dial the score clears. Both entries are copied verbatim from
 * DeterministicImportanceRules (`explicit_decision`, +6) — the gate re-runs the
 * real policy over these stored rules, so their `adjustment` signs are the whole
 * point.
 *
 * @return list<array{id: string, adjustment: int, reason: string}>
 */
function cleanRules(): array
{
    return [['id' => 'explicit_decision', 'adjustment' => 6, 'reason' => 'States an explicit decision.']];
}

/**
 * A rule set carrying a penalty (`insufficient_substance`, -10). The policy
 * refuses it at every dial, whatever the score.
 *
 * @return list<array{id: string, adjustment: int, reason: string}>
 */
function penalizedRules(): array
{
    return [['id' => 'insufficient_substance', 'adjustment' => -10, 'reason' => 'Too little substance to be useful in a later session.']];
}

/**
 * One entry as the classifier job would have left it.
 *
 * `$rules` is what gate 6 actually reasons about: together with `final_score` it
 * is the whole input of `AutoApprovalPolicy::isEligible()`, and the report re-runs
 * the policy over it at the dial in force. It is omitted by default, on purpose —
 * that is exactly what an entry classified before this feature looks like, and such
 * an entry's eligibility cannot be computed at all, so it must not be able to
 * satisfy the anti-vacuity floor.
 *
 * `$wouldApprove` / `$autoApproveThreshold` are the stamps `ClassifyKnowledgeEntryJob::decide()`
 * wrote at classify time. No gate reads them any more; they are still written here
 * because the job writes them, and because `rag_status` and the review-reduction
 * line still report `would_approve` in aggregate.
 *
 * @param  list<array{id: string, adjustment: int, reason: string}>|null  $rules
 */
function reportEntry(
    string $status,
    bool $wouldReject,
    int $score,
    string $mode = 'shadow',
    string $projectId = 'report-project',
    ?bool $wouldApprove = null,
    ?int $autoApproveThreshold = 90,
    ?array $rules = null,
): KnowledgeEntry {
    $importance = [
        'mode' => $mode,
        'verdict' => $wouldReject ? 'not_important' : 'important',
        'would_reject' => $wouldReject,
        'final_score' => $score,
        'classified_at' => now()->toIso8601String(),
    ];

    if ($rules !== null) {
        $importance['rules'] = $rules;
    }

    if ($wouldApprove !== null) {
        $importance['would_approve'] = $wouldApprove;
        $importance['auto_approve_threshold'] = $autoApproveThreshold;
    }

    return KnowledgeEntry::create([
        'project_id' => $projectId,
        'title' => 'Entry '.str()->random(8),
        'content' => 'Content',
        'category' => 'insight',
        'status' => $status,
        'metadata' => [
            'importance' => $importance,
        ],
    ]);
}

/**
 * A shadow sample that clears every gate: 60 human-reviewed entries, 20 of them
 * correctly rejected (33% projected reduction), none of the 40 approved entries
 * marked would_reject, nothing stale.
 */
function readySample(): void
{
    // The rules matter, not just `wouldReject: true`: gate 6 recomputes each
    // rejection's eligibility from its stored `final_score` and `rules`, so a
    // rejection with no stored rules is not computable and cannot count toward
    // the floor. A score of 15 under a penalty is refused at every dial.
    for ($i = 0; $i < 20; $i++) {
        reportEntry('rejected', wouldReject: true, score: 15, wouldApprove: false, rules: penalizedRules());
    }

    for ($i = 0; $i < 40; $i++) {
        reportEntry('approved', wouldReject: false, score: 85);
    }
}

/**
 * Human-rejected, shadow-classified entries — the population the false
 * auto-approval gate is measured against.
 *
 * `mode => shadow` and a non-null `verdict` are both deliberate:
 * ImportanceStatistics::shadowClassified() filters on exactly those two things,
 * so an entry missing either is invisible to the gate and the test would pass in
 * a vacuum.
 *
 * `$wouldApprove` selects the STORED DATA the gate recomputes over: by default a
 * score of 95 with clean rules (eligible at a dial of 90) or a score of 15 under a
 * penalty (eligible at no dial at all). `$score` and `$rules` override that data
 * independently of the stamp, which is how a test can model the entry the old
 * stamp-reading gate got wrong — data that says one thing, stamp that says another.
 *
 * @param  list<array{id: string, adjustment: int, reason: string}>|null  $rules
 */
function rejectedShadowEntries(
    int $count,
    bool $wouldApprove,
    ?int $autoApproveThreshold = 90,
    ?int $score = null,
    ?array $rules = null,
): void {
    for ($i = 0; $i < $count; $i++) {
        KnowledgeEntry::create([
            'project_id' => 'report-project',
            'title' => 'Rejected '.str()->random(8),
            'content' => 'Content',
            'category' => 'insight',
            'status' => 'rejected',
            'metadata' => [
                'importance' => [
                    'mode' => 'shadow',
                    'verdict' => $wouldApprove ? 'important' : 'not_important',
                    'would_reject' => ! $wouldApprove,
                    'would_approve' => $wouldApprove,
                    'auto_approve_threshold' => $autoApproveThreshold,
                    'auto_approved' => false,
                    'final_score' => $score ?? ($wouldApprove ? 95 : 15),
                    'rules' => $rules ?? ($wouldApprove ? cleanRules() : penalizedRules()),
                    'classified_at' => now()->toIso8601String(),
                ],
            ],
        ]);
    }
}

/**
 * Everything except the rejected sample: 45 human-approved entries and 25
 * unreviewed would-reject ones, which hold the queue-reduction gate above 30%
 * without adding to the rejected population. Every gate but the two auto-approval
 * ones is green, so whatever a test then adds as rejections is the ONLY thing
 * gate 6 can fail on.
 */
function readySampleWithoutRejections(): void
{
    for ($i = 0; $i < 45; $i++) {
        reportEntry('approved', wouldReject: false, score: 85);
    }

    for ($i = 0; $i < 25; $i++) {
        reportEntry('pending', wouldReject: true, score: 12);
    }
}

/**
 * Write a throwaway corpus file and return its path, so a test can point the
 * must-reject gate at content it controls.
 *
 * @param  array<string, mixed>  $corpus
 */
function writeTempCorpus(array $corpus): string
{
    $path = sys_get_temp_dir().'/must-reject-'.bin2hex(random_bytes(6)).'.json';

    file_put_contents($path, json_encode($corpus, JSON_THROW_ON_ERROR));

    return $path;
}

it('reports the shadow sample, score distribution and mismatches', function () {
    reportEntry('approved', wouldReject: false, score: 85);
    reportEntry('approved', wouldReject: true, score: 30);  // false reject in the live sample
    reportEntry('rejected', wouldReject: false, score: 75);  // the classifier would have kept it
    reportEntry('rejected', wouldReject: true, score: 10);
    reportEntry('pending', wouldReject: true, score: 25);    // not yet reviewed

    $this->artisan('rag:importance-report', ['--project' => 'report-project'])
        ->expectsOutputToContain('Importance calibration report — project report-project')
        // 4 of the 5 shadow-classified entries have been human-reviewed
        ->expectsOutputToContain('Human-reviewed classified entries: 4 (minimum 50)')
        // score distribution buckets
        ->expectsOutputToContain('0-19')
        ->expectsOutputToContain('80-100')
        // 3 of 5 classified entries carry would_reject
        ->expectsOutputToContain('Projected queue reduction: 60.0% (3 of 5)')
        ->expectsOutputToContain('Human-approved entries the classifier would have rejected: 1 of 2 (50.0%)')
        ->expectsOutputToContain('Human-rejected entries the classifier would have kept: 1 of 2')
        ->expectsOutputToContain('Stale classifying entries: 0')
        ->expectsOutputToContain('Must-keep corpus false rejects: 0')
        ->expectsOutputToContain('NOT READY')
        ->assertExitCode(1);
});

it('declares readiness only when every gate holds', function () {
    readySample();

    $this->artisan('rag:importance-report', ['--project' => 'report-project'])
        ->expectsOutputToContain('Human-reviewed classified entries: 60 (minimum 50)')
        ->expectsOutputToContain('Projected queue reduction: 33.3% (20 of 60)')
        ->expectsOutputToContain('Human-approved entries the classifier would have rejected: 0 of 40 (0.0%)')
        ->expectsOutputToContain('READY')
        ->assertExitCode(0);
});

it('is not ready while the reviewed sample is below the minimum', function () {
    readySample();

    // One entry short of the 50-entry gate (20 rejected + 29 approved = 49),
    // with every other gate still green.
    $ids = KnowledgeEntry::query()
        ->where('project_id', 'report-project')
        ->where('status', 'approved')
        ->limit(11)
        ->pluck('id')
        ->all();
    KnowledgeEntry::query()->whereIn('id', $ids)->delete();

    $this->artisan('rag:importance-report', ['--project' => 'report-project'])
        ->expectsOutputToContain('Human-reviewed classified entries: 49 (minimum 50)')
        ->expectsOutputToContain('NOT READY')
        ->assertExitCode(1);
});

it('honours a custom minimum sample size', function () {
    // Ten rejections, because the false-auto-approval gate refuses to certify
    // auto-approval on a smaller rejected sample — lowering --min-sample cannot
    // buy a way past that floor.
    for ($i = 0; $i < 10; $i++) {
        reportEntry('rejected', wouldReject: true, score: 15, wouldApprove: false, rules: penalizedRules());
    }

    reportEntry('approved', wouldReject: false, score: 85);

    $this->artisan('rag:importance-report', ['--project' => 'report-project', '--min-sample' => 11])
        ->expectsOutputToContain('Human-reviewed classified entries: 11 (minimum 11)')
        ->expectsOutputToContain('READY')
        ->assertExitCode(0);
});

it('rejects a non-positive --min-sample instead of silently coercing it to 1', function () {
    $this->artisan('rag:importance-report', ['--project' => 'report-project', '--min-sample' => 0])
        ->expectsOutputToContain("Invalid --min-sample '0'; expected a positive integer.")
        ->assertExitCode(1);
});

it('rejects a non-numeric --min-sample', function () {
    $this->artisan('rag:importance-report', ['--project' => 'report-project', '--min-sample' => 'abc'])
        ->expectsOutputToContain("Invalid --min-sample 'abc'; expected a positive integer.")
        ->assertExitCode(1);
});

it('fails the false-reject gate when more than 5% of approved entries are marked would_reject', function () {
    readySample();

    // 3 of 40 approved entries (7.5%) would have been rejected.
    KnowledgeEntry::query()
        ->where('project_id', 'report-project')
        ->where('status', 'approved')
        ->limit(3)
        ->get()
        ->each(function (KnowledgeEntry $entry) {
            $metadata = $entry->metadata;
            $metadata['importance']['would_reject'] = true;
            $metadata['importance']['verdict'] = 'not_important';
            $entry->metadata = $metadata;
            $entry->save();
        });

    $this->artisan('rag:importance-report', ['--project' => 'report-project'])
        ->expectsOutputToContain('Human-approved entries the classifier would have rejected: 3 of 40 (7.5%)')
        ->expectsOutputToContain('NOT READY')
        ->assertExitCode(1);
});

it('fails the false-reject gate when zero entries are approved, instead of passing on no evidence', function () {
    // 50 rejected, 0 approved. approved_would_reject / approved would be a
    // division by zero; the old `approved > 0 ? ... : 0.0` fallback read that
    // as a clean 0.0% pass with zero evidence, which also pushed the reduction
    // gate to a vacuous 100%.
    for ($i = 0; $i < 50; $i++) {
        reportEntry('rejected', wouldReject: true, score: 15);
    }

    $this->artisan('rag:importance-report', ['--project' => 'report-project'])
        ->expectsOutputToContain('Human-approved entries the classifier would have rejected: 0 of 0 (0.0%)')
        ->expectsOutputToContain('NOT READY')
        ->assertExitCode(1);
});

it('fails the queue-reduction gate below 30%', function () {
    // 55 reviewed entries, only 10 (18.2%) would have been rejected.
    for ($i = 0; $i < 10; $i++) {
        reportEntry('rejected', wouldReject: true, score: 15);
    }

    for ($i = 0; $i < 45; $i++) {
        reportEntry('approved', wouldReject: false, score: 85);
    }

    $this->artisan('rag:importance-report', ['--project' => 'report-project'])
        ->expectsOutputToContain('Projected queue reduction: 18.2% (10 of 55)')
        ->expectsOutputToContain('NOT READY')
        ->assertExitCode(1);
});

it('fails the staleness gate when an entry is stuck in classifying', function () {
    readySample();

    $stuck = KnowledgeEntry::create([
        'project_id' => 'report-project',
        'title' => 'Stuck',
        'content' => 'Content',
        'category' => 'insight',
        'status' => 'classifying',
    ]);
    DB::table('knowledge_entries')->where('id', $stuck->id)->update([
        'updated_at' => now()->subMinutes((int) config('rag.importance.stale_after_minutes') + 1),
    ]);

    $this->artisan('rag:importance-report', ['--project' => 'report-project'])
        ->expectsOutputToContain('Stale classifying entries: 1')
        ->expectsOutputToContain('NOT READY')
        ->assertExitCode(1);
});

it('fails the must-keep gate when the current rules hard-veto a corpus fixture', function () {
    readySample();

    // Empty content is a hard veto in DeterministicImportanceRules — a single
    // fixture like this is exactly what the gate exists to catch: reviewed
    // knowledge the current rules would now destroy.
    $corpus = tempnam(sys_get_temp_dir(), 'must_keep_').'.json';
    file_put_contents($corpus, json_encode([
        'fixtures' => [
            [
                'id' => 'veto-regression',
                'candidate' => [
                    'title' => 'Anything',
                    'content' => '',
                    'category' => 'insight',
                    'source' => 'condense',
                ],
            ],
        ],
    ]));
    config(['rag.importance.must_keep_corpus_path' => $corpus]);

    $this->artisan('rag:importance-report', ['--project' => 'report-project'])
        ->expectsOutputToContain('Must-keep corpus false rejects: 1 of 1')
        ->expectsOutputToContain('NOT READY')
        ->assertExitCode(1);

    unlink($corpus);
});

it('fails the must-keep gate — never READY — when the corpus is missing', function () {
    readySample();

    // This is the branch that fires in production when the command runs
    // through its documented `docker compose exec app ...` invocation before
    // the corpus is shipped at a path the image actually contains: an
    // unreadable corpus must never read as a pass.
    $missing = sys_get_temp_dir().'/rag-missing-must-keep-'.bin2hex(random_bytes(4)).'.json';
    config(['rag.importance.must_keep_corpus_path' => $missing]);

    $this->artisan('rag:importance-report', ['--project' => 'report-project'])
        ->expectsOutputToContain("Must-keep corpus false rejects: corpus unavailable at {$missing}")
        ->expectsOutputToContain('NOT READY')
        ->assertExitCode(1);
});

it('counts only shadow-mode verdicts', function () {
    readySample();

    // Enforce rejections also carry `would_reject`, but they are not shadow
    // evidence: counting them would inflate the projected reduction.
    for ($i = 0; $i < 20; $i++) {
        reportEntry('rejected', wouldReject: true, score: 8, mode: 'enforce');
    }

    $this->artisan('rag:importance-report', ['--project' => 'report-project'])
        ->expectsOutputToContain('Projected queue reduction: 33.3% (20 of 60)')
        ->expectsOutputToContain('Human-reviewed classified entries: 60 (minimum 50)')
        ->assertExitCode(0);
});

it('ignores other projects', function () {
    Project::create([
        'id' => 'other-project',
        'name' => 'Other Project',
        'root_path' => '/tmp/other-project',
        'language' => 'en',
    ]);

    readySample();

    for ($i = 0; $i < 30; $i++) {
        reportEntry('approved', wouldReject: true, score: 10, projectId: 'other-project');
    }

    $this->artisan('rag:importance-report', ['--project' => 'report-project'])
        ->expectsOutputToContain('Human-approved entries the classifier would have rejected: 0 of 40 (0.0%)')
        ->expectsOutputToContain('READY')
        ->assertExitCode(0);
});

it('never changes the classifier mode, even when every gate holds', function () {
    ImportanceClassifierSetting::query()->findOrFail(1)->update(['mode' => 'shadow', 'threshold' => 70]);
    readySample();

    $this->artisan('rag:importance-report', ['--project' => 'report-project'])
        ->expectsOutputToContain('READY')
        ->assertExitCode(0);

    expect(ImportanceClassifierSetting::current()->mode->value)->toBe('shadow')
        ->and(ImportanceClassifierSetting::current()->threshold)->toBe(70);

    $this->assertDatabaseHas('importance_classifier_settings', [
        'id' => 1,
        'mode' => 'shadow',
    ]);
});

it('reports an unknown project instead of inventing a sample', function () {
    $this->artisan('rag:importance-report', ['--project' => 'nope'])
        ->expectsOutputToContain("Project 'nope' not found")
        ->assertExitCode(1);
});

it('fails readiness when the classifier would have auto-approved something a human rejected', function () {
    readySample();

    // One entry a human threw away that the classifier would have approved with
    // nobody reading it. Every other gate still holds, so the report must fail on
    // this gate ALONE.
    rejectedShadowEntries(count: 1, wouldApprove: true);

    $this->artisan('rag:importance-report', ['--project' => 'report-project'])
        ->expectsOutputToContain('Human-rejected entries the classifier would have auto-approved: 1 of 21')
        ->expectsOutputToContain('False auto-approvals')
        ->expectsOutputToContain('NOT READY — 1 of 7 rollout gates fail')
        ->assertExitCode(1);
})->note('Zero tolerance: one silently-approved piece of junk is the failure this feature must not produce.');

it('fails readiness when too few entries were human-rejected to validate auto-approval', function () {
    // A sample that clears every OTHER gate on nine rejections.
    readySampleWithoutRejections();

    rejectedShadowEntries(count: 9, wouldApprove: false);

    $this->artisan('rag:importance-report', ['--project' => 'report-project'])
        ->expectsOutputToContain('Human-rejected entries the classifier would have auto-approved: 0 of 9')
        ->expectsOutputToContain('NOT READY — 1 of 7 rollout gates fail')
        ->assertExitCode(1);
})->note('Anti-vacuity: "zero false approvals among zero rejections" proves nothing. The same defect was already found once in the false-reject gate.');

it('fails readiness when human-rejected entries predate the feature and cannot clear the floor', function () {
    // The normal upgrade path this feature is built around: an operator ran shadow
    // for weeks *before* the classifier persisted its `rules`, so their rejected
    // entries carry a verdict and nothing gate 6 can reason with. Eligibility cannot
    // be recomputed for them at any dial — not "false", unknowable — so they are not
    // evidence, and the floor must not accept them.
    //
    // A sample that clears every OTHER gate, plus 15 such legacy shadow rejections:
    // comfortably over the `rejected >= 10` floor if the raw rejection count were
    // used, and worth exactly nothing.
    readySampleWithoutRejections();

    for ($i = 0; $i < 15; $i++) {
        reportEntry('rejected', wouldReject: true, score: 15); // no rules key: legacy shape
    }

    $this->artisan('rag:importance-report', ['--project' => 'report-project'])
        ->expectsOutputToContain('Human-rejected entries the classifier would have auto-approved: 0 of 0')
        ->expectsOutputToContain('NOT READY — 1 of 7 rollout gates fail')
        ->assertExitCode(1);
})->note('The vacuous pass the floor exists to prevent, wearing a denominator of 15: entries whose eligibility can never be computed are not a clean record, they are no record.');

it('recomputes eligibility over rejections recorded while auto-approval was off, and finds the false approvals in them', function () {
    // The failure the `would_approve` stamp made invisible, and the reason gate 6
    // recomputes:
    //
    //   1. the operator calibrates for weeks at `auto_approve_threshold = null`.
    //      `isEligible()` refuses a null dial, so every one of those 100 rejections
    //      is stamped `would_approve: false` — truthfully, about a dial of `null`;
    //   2. THREE of them stored a `final_score` of 95 with clean rules. At a dial of
    //      90 each is a false auto-approval, sitting in the database, computable;
    //   3. the operator sets the dial to 90 and gathers 10 fresh clean rejections.
    //
    // The stamp-reading gate dropped all 100 (their recorded dial was `null`, not
    // 90), read a spotless "0 of 10", met the floor and certified the classifier.
    // Recomputing at the dial in force reads all 110 and finds the 3.
    readySampleWithoutRejections();

    rejectedShadowEntries(count: 97, wouldApprove: false, autoApproveThreshold: null);
    rejectedShadowEntries(
        count: 3,
        wouldApprove: false,          // stamped false: the dial was null when it was judged
        autoApproveThreshold: null,
        score: 95,                    // …but the DATA is eligible at 90
        rules: cleanRules(),
    );
    rejectedShadowEntries(count: 10, wouldApprove: false, autoApproveThreshold: 90);

    ImportanceClassifierSetting::query()->findOrFail(1)->update(['auto_approve_threshold' => 90]);

    $this->artisan('rag:importance-report', ['--project' => 'report-project'])
        ->expectsOutputToContain('auto-approve 90')
        ->expectsOutputToContain('Human-rejected entries the classifier would have auto-approved: 3 of 110')
        ->expectsOutputToContain('NOT READY — 1 of 7 rollout gates fail')
        ->assertExitCode(1);
})->note('Contrary evidence a human already produced must not be thrown away because of the dial it happened to be stamped with.');

it('does not let a would_approve stamped at a lower dial block a higher one forever', function () {
    // The brick. `isEligible()` is monotone decreasing in the dial, so an entry
    // stamped `would_approve: true` at 60 with a score of 65 is NOT eligible at 90.
    // The stamp-reading gate counted it anyway (it counted a `true` recorded at ANY
    // dial), and rejected entries are terminal — never re-classified — so no amount
    // of fresh clean evidence could ever bring gate 6 back to a pass, and the
    // operator's only remedy was to hand-edit metadata.
    //
    // Recomputing asks the policy instead of the stamp: at 90, a 65 is refused, so
    // this entry is not a false approval and does not block. It is still COUNTED —
    // its eligibility is computable — so it is part of the floor's population.
    readySampleWithoutRejections();

    rejectedShadowEntries(count: 10, wouldApprove: false, autoApproveThreshold: 90);
    rejectedShadowEntries(
        count: 1,
        wouldApprove: true,           // stamped true, truthfully, at a dial of 60
        autoApproveThreshold: 60,
        score: 65,
        rules: cleanRules(),
    );

    ImportanceClassifierSetting::query()->findOrFail(1)->update(['auto_approve_threshold' => 90]);

    $this->artisan('rag:importance-report', ['--project' => 'report-project'])
        ->expectsOutputToContain('Human-rejected entries the classifier would have auto-approved: 0 of 11')
        ->expectsOutputToContain('READY')
        ->assertExitCode(0);
})->note('A gate no fresh evidence can ever clear is not a gate, it is a brick.');

it('counts rejections recorded at any dial, because their eligibility is recomputed at the one in force', function () {
    // The control for both tests above: 15 rejections recorded while auto-approval
    // was OFF, whose stored score and rules are refused at 90. They are evidence
    // about the dial in force — the recomputation says so — the floor is met on
    // them, and the gate passes. The dial they happened to be stamped with never
    // enters into it.
    readySampleWithoutRejections();

    rejectedShadowEntries(count: 15, wouldApprove: false, autoApproveThreshold: null);

    ImportanceClassifierSetting::query()->findOrFail(1)->update(['auto_approve_threshold' => 90]);

    $this->artisan('rag:importance-report', ['--project' => 'report-project'])
        ->expectsOutputToContain('Human-rejected entries the classifier would have auto-approved: 0 of 15')
        ->expectsOutputToContain('READY')
        ->assertExitCode(0);
});

it('fails readiness on a false auto-approval the stored data proves at the dial in force', function () {
    // With the population correct, one entry a human threw away whose stored score
    // and rules the policy approves at THIS dial still fails the gate. The
    // recomputation cannot hide a real false auto-approval.
    readySampleWithoutRejections();

    rejectedShadowEntries(count: 14, wouldApprove: false, autoApproveThreshold: 90);
    rejectedShadowEntries(count: 1, wouldApprove: true, autoApproveThreshold: 90);

    ImportanceClassifierSetting::query()->findOrFail(1)->update(['auto_approve_threshold' => 90]);

    $this->artisan('rag:importance-report', ['--project' => 'report-project'])
        ->expectsOutputToContain('Human-rejected entries the classifier would have auto-approved: 1 of 15')
        ->expectsOutputToContain('NOT READY — 1 of 7 rollout gates fail')
        ->assertExitCode(1);
});

it('fails readiness when the whole rejected sample would be auto-approved at the dial in force', function () {
    // The other direction of a dial move, and the one an operator makes after
    // reading the score distribution: LOWERING it. Twelve rejections were judged at
    // 95 and stamped `would_approve: false` — truthfully, at 95, because they score
    // 92. The operator now proposes 90, and at 90 every one of those 12 is a false
    // auto-approval: clean rules, score over the dial, thrown away by a human.
    //
    // Nothing about them changed except the dial, and the stamp cannot see that. The
    // recomputation reads their stored score and rules against the dial in force and
    // finds all twelve — where the stamp-reading gate saw a population of zero, and
    // would have certified the classifier the moment ten fresh clean rejections
    // arrived at 90, with these twelve still sitting in the base.
    readySampleWithoutRejections();

    rejectedShadowEntries(
        count: 12,
        wouldApprove: false,          // stamped at 95: 92 < 95, so not eligible THEN
        autoApproveThreshold: 95,
        score: 92,                    // …but 92 >= 90, with clean rules: eligible NOW
        rules: cleanRules(),
    );

    ImportanceClassifierSetting::query()->findOrFail(1)->update(['auto_approve_threshold' => 90]);

    $this->artisan('rag:importance-report', ['--project' => 'report-project'])
        ->expectsOutputToContain('Human-rejected entries the classifier would have auto-approved: 12 of 12')
        ->expectsOutputToContain('False auto-approvals')
        ->expectsOutputToContain('NOT READY — 1 of 7 rollout gates fail')
        ->assertExitCode(1);
})->note('Lowering the dial can only create false approvals, never remove them: the gate must recompute, not remember.');

it('prints the auto-approve dial the two approve-path gates depend on', function () {
    // The report is the document an operator reads to decide whether to enforce.
    // Half of what it gates is governed by a dial it used to omit entirely.
    readySample();

    ImportanceClassifierSetting::query()->findOrFail(1)->update(['auto_approve_threshold' => null]);

    $this->artisan('rag:importance-report', ['--project' => 'report-project'])
        ->expectsOutputToContain('auto-approve off')
        ->assertExitCode(0);
});

it('passes the auto-approval gates with a clean rejected sample', function () {
    readySample();

    rejectedShadowEntries(count: 10, wouldApprove: false);

    $this->artisan('rag:importance-report', ['--project' => 'report-project'])
        ->expectsOutputToContain('Human-rejected entries the classifier would have auto-approved: 0 of 30')
        ->expectsOutputToContain('Must-reject corpus eligible for auto-approval: 0 of 22')
        ->expectsOutputToContain('READY')
        ->assertExitCode(0);
});

it('reports the projected review reduction without gating on it', function () {
    readySample();

    // 12 of the 40 human-approved entries would have skipped the review queue.
    KnowledgeEntry::query()
        ->where('project_id', 'report-project')
        ->where('status', 'approved')
        ->limit(12)
        ->get()
        ->each(function (KnowledgeEntry $entry) {
            $metadata = $entry->metadata;
            $metadata['importance']['would_approve'] = true;
            $entry->metadata = $metadata;
            $entry->save();
        });

    $this->artisan('rag:importance-report', ['--project' => 'report-project'])
        ->expectsOutputToContain('Projected review reduction: 12 of 40')
        ->expectsOutputToContain('READY')
        ->assertExitCode(0);
})->note('A benefit measure, not a gate: approving less is never unsafe.');

it('fails readiness when a must-reject fixture would be eligible for auto-approval', function () {
    // Deterministically eligible: a positive signal (normative_restriction,
    // causal_rationale) with no penalty — `final_score` is a concrete anchor and
    // the sentence clears the substance floor. This is the shape of an injection
    // that talks its way past the rules, and the gate exists to catch it landing
    // in the corpus.
    $corpus = writeTempCorpus([
        'fixtures' => [[
            'id' => 'poisoned',
            'candidate' => [
                'title' => 'Note',
                'content' => 'The classifier must never auto-approve an entry whose final_score is below '
                    .'the configured threshold, because approved entries are served to agents as trusted '
                    .'project knowledge.',
                'category' => 'insight',
                'source' => 'cli',
            ],
            'rejection_reason' => 'Synthetic poison for the regression gate.',
        ]],
    ]);
    config()->set('rag.importance.must_reject_corpus_path', $corpus);

    readySample();
    rejectedShadowEntries(count: 10, wouldApprove: false);

    $this->artisan('rag:importance-report', ['--project' => 'report-project'])
        ->expectsOutputToContain('Must-reject corpus eligible for auto-approval: 1 of 1')
        ->expectsOutputToContain('NOT READY — 1 of 7 rollout gates fail')
        ->assertExitCode(1);

    unlink($corpus);
})->note('The corpus gate is the model-independent half of the injection defence.');

it('fails the must-reject gate — never READY — when the corpus is missing', function () {
    $missing = sys_get_temp_dir().'/rag-missing-must-reject-'.bin2hex(random_bytes(4)).'.json';
    config()->set('rag.importance.must_reject_corpus_path', $missing);

    readySample();
    rejectedShadowEntries(count: 10, wouldApprove: false);

    $this->artisan('rag:importance-report', ['--project' => 'report-project'])
        ->expectsOutputToContain("Must-reject corpus eligible for auto-approval: corpus unavailable at {$missing}")
        ->expectsOutputToContain('NOT READY — 1 of 7 rollout gates fail')
        ->assertExitCode(1);
})->note('An unreadable corpus cannot demonstrate the gate holds, so it must fail it.');

it('skips the auto-approval gates when auto-approval is disabled', function () {
    ImportanceClassifierSetting::query()->findOrFail(1)->update(['auto_approve_threshold' => null]);

    // Everything the two auto-approval gates exist to catch is present: a false
    // auto-approval, a poisoned corpus, and no rejected sample worth the name.
    // With auto-approval off there is nothing to approve, so none of it can block
    // an operator who only wants the reject path.
    config()->set('rag.importance.must_reject_corpus_path', writeTempCorpus([
        'fixtures' => [[
            'id' => 'poisoned',
            'candidate' => [
                'title' => 'Note',
                'content' => 'The classifier must never auto-approve an entry whose final_score is below '
                    .'the configured threshold, because approved entries are served to agents as trusted '
                    .'project knowledge.',
                'category' => 'insight',
                'source' => 'cli',
            ],
        ]],
    ]));

    readySample();
    rejectedShadowEntries(count: 1, wouldApprove: true);

    $this->artisan('rag:importance-report', ['--project' => 'report-project'])
        ->expectsOutputToContain('auto-approval disabled')
        ->expectsOutputToContain('READY')
        ->assertExitCode(0);
})->note('A reject-only operator must still be able to reach READY.');
