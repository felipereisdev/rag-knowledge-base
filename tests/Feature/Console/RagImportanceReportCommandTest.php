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
 * One entry as the classifier job would have left it.
 */
function reportEntry(
    string $status,
    bool $wouldReject,
    int $score,
    string $mode = 'shadow',
    string $projectId = 'report-project',
): KnowledgeEntry {
    return KnowledgeEntry::create([
        'project_id' => $projectId,
        'title' => 'Entry '.str()->random(8),
        'content' => 'Content',
        'category' => 'insight',
        'status' => $status,
        'metadata' => [
            'importance' => [
                'mode' => $mode,
                'verdict' => $wouldReject ? 'not_important' : 'important',
                'would_reject' => $wouldReject,
                'final_score' => $score,
                'classified_at' => now()->toIso8601String(),
            ],
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
    for ($i = 0; $i < 20; $i++) {
        reportEntry('rejected', wouldReject: true, score: 15);
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
 * so an entry missing either is invisible to the gate and the test would pass
 * in a vacuum.
 */
function rejectedShadowEntries(int $count, bool $wouldApprove): void
{
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
                    'auto_approved' => false,
                    'final_score' => $wouldApprove ? 95 : 15,
                    'classified_at' => now()->toIso8601String(),
                ],
            ],
        ]);
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
        reportEntry('rejected', wouldReject: true, score: 15);
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
    // A sample that clears every OTHER gate on nine rejections: 45 approved, 9
    // rejected, and 25 unreviewed entries the classifier would reject (which lift
    // the projected reduction without adding to the rejected population).
    for ($i = 0; $i < 45; $i++) {
        reportEntry('approved', wouldReject: false, score: 85);
    }

    for ($i = 0; $i < 25; $i++) {
        reportEntry('pending', wouldReject: true, score: 12);
    }

    rejectedShadowEntries(count: 9, wouldApprove: false);

    $this->artisan('rag:importance-report', ['--project' => 'report-project'])
        ->expectsOutputToContain('Human-rejected entries the classifier would have auto-approved: 0 of 9')
        ->expectsOutputToContain('NOT READY — 1 of 7 rollout gates fail')
        ->assertExitCode(1);
})->note('Anti-vacuity: "zero false approvals among zero rejections" proves nothing. The same defect was already found once in the false-reject gate.');

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
