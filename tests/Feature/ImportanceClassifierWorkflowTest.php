<?php

use App\Enums\ImportanceAssessmentStatus;
use App\Enums\ImportanceClassifierMode;
use App\Enums\ImportanceVerdict;
use App\Enums\KnowledgeSource;
use App\Enums\KnowledgeStatus;
use App\Jobs\ClassifyKnowledgeEntryJob;
use App\Jobs\IndexEntryJob;
use App\Mcp\Servers\RagServer;
use App\Mcp\Tools\RagStoreKnowledgeTool;
use App\Models\ImportanceAssessment;
use App\Models\ImportanceClassifierSetting;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Services\Importance\DeterministicImportanceRules;
use App\Services\Importance\ImportanceClassificationException;
use App\Services\Importance\ImportancePrompt;
use App\Services\Importance\NormalizedImportanceCandidate;
use App\Services\Importance\SemanticImportanceAssessment;
use App\Services\Importance\SemanticImportanceJudge;
use App\Services\Importing\DocumentImporter;
use App\Services\Indexing\EntryIndexer;
use App\Services\Knowledge\KnowledgeWriter;
use App\Services\Search\HybridSearcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

/**
 * The whole importance lifecycle, driven end to end through the real ingestion
 * door, the real job, the real classifier, the real observer, the real indexer
 * and the real searcher.
 *
 * Two things are faked, and only two:
 *
 *  - the SEMANTIC JUDGE, so the `claude` binary is never launched. It is
 *    injected into the container, so every layer above it is production code;
 *  - the QUEUE (globally, in `Tests\TestCase`) and the EMBEDDER, so nothing
 *    reaches a worker or an HTTP endpoint by accident.
 *
 * The queue being faked is load-bearing here rather than incidental.
 * `phpunit.xml` sets `QUEUE_CONNECTION=sync`, but `ClassifyKnowledgeEntryJob`
 * pins itself to the `classification` CONNECTION (whose `retry_after` is
 * deliberately sized above the job's own timeout), so a dispatched job would
 * land in the `jobs` table rather than run inline. These tests therefore assert
 * the dispatch AND then run the job themselves — a test that stopped at "a job
 * was dispatched" would prove nothing about what the job does.
 */
final class WorkflowJudge implements SemanticImportanceJudge
{
    public int $calls = 0;

    /** @var list<NormalizedImportanceCandidate> */
    public array $seen = [];

    public function __construct(private SemanticImportanceAssessment|Throwable $response) {}

    public function assess(NormalizedImportanceCandidate $candidate): SemanticImportanceAssessment
    {
        $this->calls++;
        $this->seen[] = $candidate;

        if ($this->response instanceof Throwable) {
            throw $this->response;
        }

        return $this->response;
    }
}

beforeEach(function () {
    Queue::fake();

    $this->vector = array_fill(0, 768, 0.1);

    // A closure fake, not a queue of canned responses: the indexer and the
    // searcher each embed, in whatever order a given test needs them, and one
    // vector per input is always the right answer.
    Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_fill(0, count($prompt->inputs), $this->vector));

    Project::create(['id' => 'wf', 'name' => 'Workflow', 'root_path' => '/tmp/wf', 'language' => 'en']);
});

/**
 * Durable, decision-bearing knowledge that triggers no deterministic veto, so
 * every test below is exercising the SEMANTIC half and the threshold — not a
 * rule quietly deciding the outcome for it.
 */
const WORKFLOW_CONTENT = 'Orders above 1000 EUR must be approved by a manager before the shipping label is bought, because a rejected label is not refundable and the courier keeps the fee.';

function workflowJudgement(int $semanticScore): SemanticImportanceAssessment
{
    return new SemanticImportanceAssessment(
        durability: 0,
        actionability: 0,
        specificity: 0,
        nonObviousness: 0,
        futureValue: 0,
        semanticScore: $semanticScore,
        recommendedVerdict: ImportanceVerdict::NotImportant,
        reasons: [['criterion' => 'durability', 'explanation' => 'Synthetic reason.']],
    );
}

/**
 * Inject the semantic half. `HybridImportanceClassifier` resolves the judge out
 * of the container, so this is the single seam the whole suite needs.
 */
function workflowJudge(SemanticImportanceAssessment|Throwable $response): WorkflowJudge
{
    $judge = new WorkflowJudge($response);
    app()->instance(SemanticImportanceJudge::class, $judge);

    return $judge;
}

function workflowSettings(ImportanceClassifierMode $mode, int $threshold = 70): void
{
    ImportanceClassifierSetting::query()->findOrFail(1)->update([
        'mode' => $mode->value,
        'threshold' => $threshold,
    ]);
}

/** Run the queued classification job for real, resolving its dependencies from the container. */
function runClassificationJob(int $entryId): void
{
    app()->call([new ClassifyKnowledgeEntryJob($entryId), 'handle']);
}

/** Run the queued indexing job for real, so chunks come from the production indexer. */
function runIndexing(int $entryId): void
{
    (new IndexEntryJob($entryId))->handle(app(EntryIndexer::class));
}

function storeThroughWriter(string $title, KnowledgeSource $source = KnowledgeSource::Mcp, string $content = WORKFLOW_CONTENT): KnowledgeEntry
{
    return app(KnowledgeWriter::class)->store(
        projectId: 'wf',
        title: $title,
        content: $content,
        category: 'business-rule',
        source: $source,
    );
}

function chunkCount(int $entryId): int
{
    return DB::table('chunk_embeddings')->where('entry_id', $entryId)->count();
}

/**
 * @return list<int>
 */
function searchEntryIds(string $query): array
{
    return array_map(
        static fn ($result): int => $result->entryId,
        (new HybridSearcher)->search($query, 'wf'),
    );
}

describe('workflow 1: an MCP write returns promptly with classifying', function () {
    it('answers the caller before anything is judged', function () {
        workflowSettings(ImportanceClassifierMode::Shadow);
        $judge = workflowJudge(workflowJudgement(80));

        $response = RagServer::tool(RagStoreKnowledgeTool::class, [
            'project_id' => 'wf',
            'title' => 'Manager approval above 1000 EUR',
            'content' => WORKFLOW_CONTENT,
            'category' => 'business-rule',
        ]);

        $entry = KnowledgeEntry::query()->where('title', 'Manager approval above 1000 EUR')->firstOrFail();

        // The write path itself never judges: the model call happens on a worker,
        // so the tool answers at the speed of an INSERT.
        expect($judge->calls)->toBe(0)
            ->and($entry->status)->toBe(KnowledgeStatus::Classifying->value)
            ->and($entry->importance_assessment_id)->toBeNull()
            ->and(ImportanceAssessment::query()->count())->toBe(0);

        // And it says so, rather than promising an approval queue the entry has
        // not reached and (under `enforce`) may never reach.
        $response->assertOk()
            ->assertSee('being classified for importance')
            ->assertDontSee('pending approval');

        Queue::assertPushed(
            ClassifyKnowledgeEntryJob::class,
            static fn (ClassifyKnowledgeEntryJob $job): bool => $job->entryId === (int) $entry->id,
        );

        // A `classifying` entry is not in the index and is not searchable. It has
        // no verdict yet, and under `enforce` it may never earn one.
        Queue::assertNotPushed(IndexEntryJob::class);
        expect(chunkCount((int) $entry->id))->toBe(0)
            ->and(searchEntryIds('manager approval shipping label'))->toBe([]);
    });
});

describe('workflow 2: the classification job writes an audit assessment', function () {
    it('records the full decision, and links the entry to it', function () {
        workflowSettings(ImportanceClassifierMode::Shadow);
        $judge = workflowJudge(workflowJudgement(80));

        $entry = storeThroughWriter('Manager approval');
        runClassificationJob((int) $entry->id);

        $assessment = ImportanceAssessment::query()->sole();
        $entry->refresh();

        // The hash the assessment is cached under is the hash of the candidate the
        // judge was actually handed — not a hash recomputed from a candidate this
        // test rebuilt by hand, which would only prove the test can copy a struct.
        $expectedHash = $judge->seen[0]->hash();

        expect($judge->calls)->toBe(1)
            ->and($assessment->status)->toBe(ImportanceAssessmentStatus::Succeeded)
            ->and($assessment->project_id)->toBe('wf')
            ->and($assessment->candidate_hash)->toBe($expectedHash)
            ->and($assessment->model)->toBe(config('rag.importance.model'))
            ->and($assessment->prompt_version)->toBe(ImportancePrompt::VERSION)
            ->and($assessment->rules_version)->toBe(DeterministicImportanceRules::VERSION)
            ->and($assessment->semantic_score)->toBe(80)
            // The content states a rule and a reason: +6 and +5.
            ->and($assessment->final_score)->toBe(91)
            ->and($assessment->verdict)->toBe(ImportanceVerdict::Important)
            ->and($assessment->reasons)->not->toBeEmpty()
            ->and(array_column($assessment->rules, 'id'))
            ->toEqualCanonicalizing(['normative_restriction', 'causal_rationale'])
            ->and($assessment->error_code)->toBeNull();

        // The audit trail is reachable FROM the entry, which is the only way an
        // administrator ever finds it.
        expect($entry->importance_assessment_id)->toBe($assessment->id)
            ->and($entry->status)->toBe(KnowledgeStatus::Pending->value)
            ->and($entry->metadata['importance'])->toMatchArray([
                'semantic_score' => 80,
                'final_score' => 91,
                'verdict' => 'important',
                'mode' => 'shadow',
                'would_reject' => false,
                'cache_hit' => false,
                'candidate_hash' => $expectedHash,
            ]);
    });
});

describe('workflow 3: shadow mode never rejects', function () {
    it('releases a not-important entry to pending, flagged with what enforce would have done', function () {
        workflowSettings(ImportanceClassifierMode::Shadow, threshold: 70);
        workflowJudge(workflowJudgement(40));

        $entry = storeThroughWriter('Low-value note');
        runClassificationJob((int) $entry->id);
        $entry->refresh();

        // 40 + 11 = 51, under the threshold: not important. Shadow observes it and
        // does nothing about it — that is the whole point of the mode.
        expect($entry->status)->toBe(KnowledgeStatus::Pending->value)
            ->and($entry->metadata['importance']['verdict'])->toBe('not_important')
            ->and($entry->metadata['importance']['would_reject'])->toBeTrue()
            ->and($entry->metadata['importance']['mode'])->toBe('shadow')
            ->and($entry->metadata['importance']['final_score'])->toBe(51);

        // And it reaches the approval queue exactly like any other pending entry.
        Queue::assertPushed(
            IndexEntryJob::class,
            static fn (IndexEntryJob $job): bool => (int) $job->entryId === (int) $entry->id,
        );
    });
});

describe('workflow 4: enforce mode rejects, recoverably', function () {
    it('rejects a not-important entry and lets an administrator take it back', function () {
        workflowSettings(ImportanceClassifierMode::Enforce, threshold: 70);
        workflowJudge(workflowJudgement(40));

        $entry = storeThroughWriter('Low-value note');
        runClassificationJob((int) $entry->id);
        $entry->refresh();

        expect($entry->status)->toBe(KnowledgeStatus::Rejected->value)
            ->and($entry->metadata['importance']['would_reject'])->toBeTrue()
            ->and($entry->metadata['importance']['mode'])->toBe('enforce')
            ->and($entry->importance_assessment_id)->not->toBeNull()
            ->and(chunkCount((int) $entry->id))->toBe(0)
            ->and(searchEntryIds('manager approval shipping label'))->toBe([]);

        // Recoverable, and that is not a figure of speech: nothing was deleted, the
        // content is intact, the assessment that condemned it is still attached and
        // readable, and an administrator putting it back to `pending` re-indexes it
        // and can then approve it into search.
        expect(KnowledgeEntry::query()->whereKey($entry->id)->exists())->toBeTrue()
            ->and($entry->content)->toBe(WORKFLOW_CONTENT);

        $assessment = ImportanceAssessment::query()->sole();
        expect($assessment->verdict)->toBe(ImportanceVerdict::NotImportant)
            ->and($assessment->final_score)->toBe(51);

        $entry->update(['status' => KnowledgeStatus::Pending->value]);
        Queue::assertPushed(
            IndexEntryJob::class,
            static fn (IndexEntryJob $job): bool => (int) $job->entryId === (int) $entry->id,
        );

        runIndexing((int) $entry->id);
        expect(chunkCount((int) $entry->id))->toBeGreaterThan(0);

        $entry->update(['status' => KnowledgeStatus::Approved->value]);
        expect(searchEntryIds('manager approval shipping label'))->toContain((int) $entry->id);
    });
});

describe('workflow 5: a technical failure fails open', function () {
    it('never rejects an entry because something broke, even under enforce', function () {
        // Enforce is the dangerous mode, so the failure is tested there: this is
        // exactly the configuration in which a bug that treated a timeout as a
        // verdict would silently destroy knowledge.
        workflowSettings(ImportanceClassifierMode::Enforce, threshold: 70);
        $judge = workflowJudge(ImportanceClassificationException::timedOut());

        $entry = storeThroughWriter('Manager approval');
        runClassificationJob((int) $entry->id);
        $entry->refresh();

        expect($judge->calls)->toBe(1)
            ->and($entry->status)->toBe(KnowledgeStatus::Pending->value)
            ->and($entry->status)->not->toBe(KnowledgeStatus::Rejected->value)
            ->and($entry->metadata['importance']['classification_error'])->toMatchArray([
                'code' => 'timeout',
                'message' => 'Claude importance process timed out.',
            ])
            // No verdict is invented out of a failure, and no assessment is
            // attributed to the entry, because there is no usable one.
            ->and($entry->metadata['importance'])->not->toHaveKey('verdict')
            ->and($entry->importance_assessment_id)->toBeNull();

        // The failed attempt is still audited, so a run of timeouts is visible
        // rather than silent.
        $assessment = ImportanceAssessment::query()->sole();
        expect($assessment->status)->toBe(ImportanceAssessmentStatus::Failed)
            ->and($assessment->error_code)->toBe('timeout')
            ->and($assessment->verdict)->toBeNull();

        // And it is a normal pending entry: indexed, approvable, visible.
        Queue::assertPushed(
            IndexEntryJob::class,
            static fn (IndexEntryJob $job): bool => (int) $job->entryId === (int) $entry->id,
        );
    });
});

describe('workflow 6: an import is never classified', function () {
    it('lands immediately in pending with no job, no assessment and no verdict', function () {
        workflowSettings(ImportanceClassifierMode::Enforce, threshold: 70);
        $judge = workflowJudge(workflowJudgement(10));

        $path = sys_get_temp_dir().'/importance-workflow-'.uniqid().'.md';
        file_put_contents($path, "# Refund window\n\nRefunds are accepted for 14 days after delivery.\n");

        try {
            $ids = app(DocumentImporter::class)->import('wf', $path, 'documentation');
        } finally {
            @unlink($path);
        }

        $entry = KnowledgeEntry::query()->findOrFail($ids[0]);

        // A human deliberately fed this in. The classifier judges captured
        // insight, not a corpus somebody chose to import — so it does not run at
        // all, not even in `enforce`, and not even though the semantic judge here
        // would have scored the entry into rejection.
        expect($entry->status)->toBe(KnowledgeStatus::Pending->value)
            ->and($entry->source)->toBe(KnowledgeSource::Import->value)
            ->and($entry->metadata)->not->toHaveKey('importance')
            ->and($entry->importance_assessment_id)->toBeNull()
            ->and($judge->calls)->toBe(0)
            ->and(ImportanceAssessment::query()->count())->toBe(0);

        Queue::assertNotPushed(ClassifyKnowledgeEntryJob::class);
        Queue::assertPushed(
            IndexEntryJob::class,
            static fn (IndexEntryJob $job): bool => (int) $job->entryId === (int) $entry->id,
        );
    });
});

describe('workflow 7: a repeated candidate reuses the assessment', function () {
    it('judges the same knowledge once, however many times it is captured', function () {
        workflowSettings(ImportanceClassifierMode::Shadow, threshold: 70);
        $judge = workflowJudge(workflowJudgement(80));

        // The same knowledge captured twice — the ordinary case: a condensation run
        // that re-derives an insight it already stored last week. Same title, same
        // body, same category, same source, so the same cache identity.
        $first = storeThroughWriter('Manager approval', KnowledgeSource::Condense);
        $second = storeThroughWriter('Manager approval', KnowledgeSource::Condense);

        runClassificationJob((int) $first->id);
        runClassificationJob((int) $second->id);

        $first->refresh();
        $second->refresh();

        expect($judge->calls)->toBe(1, 'The second capture paid for a second model call.')
            ->and(ImportanceAssessment::query()->count())->toBe(1)
            ->and($second->importance_assessment_id)->toBe($first->importance_assessment_id)
            ->and($first->metadata['importance']['cache_hit'])->toBeFalse()
            ->and($second->metadata['importance']['cache_hit'])->toBeTrue()
            ->and($second->metadata['importance']['candidate_hash'])->toBe($first->metadata['importance']['candidate_hash'])
            ->and($second->metadata['importance']['final_score'])->toBe($first->metadata['importance']['final_score'])
            ->and($second->metadata['importance']['verdict'])->toBe('important')
            ->and($second->status)->toBe(KnowledgeStatus::Pending->value);
    });

    it('treats the same text from a different source as a different candidate', function () {
        // The source IS part of the normalized candidate, and therefore part of the
        // cache identity — the judge is told where a claim came from, and "an agent
        // typed this into the MCP tool" and "the condenser derived it from a
        // transcript" are not the same claim. So identical text from a different
        // source is judged afresh, and that is deliberate rather than a cache miss.
        workflowSettings(ImportanceClassifierMode::Shadow, threshold: 70);
        $judge = workflowJudge(workflowJudgement(80));

        $condensed = storeThroughWriter('Manager approval', KnowledgeSource::Condense);
        $written = storeThroughWriter('Manager approval', KnowledgeSource::Mcp);

        runClassificationJob((int) $condensed->id);
        runClassificationJob((int) $written->id);

        $condensed->refresh();
        $written->refresh();

        expect($judge->calls)->toBe(2)
            ->and(ImportanceAssessment::query()->count())->toBe(2)
            ->and($written->metadata['importance']['candidate_hash'])
            ->not->toBe($condensed->metadata['importance']['candidate_hash'])
            ->and($written->metadata['importance']['cache_hit'])->toBeFalse();
    });
});

describe('workflow 8: lowering the threshold re-decides without re-judging', function () {
    it('reuses the stored semantic score and changes the disposition', function () {
        workflowSettings(ImportanceClassifierMode::Enforce, threshold: 70);
        $judge = workflowJudge(workflowJudgement(55));

        // 55 + 11 = 66, under 70: rejected under enforce.
        $rejected = storeThroughWriter('Manager approval');
        runClassificationJob((int) $rejected->id);
        $rejected->refresh();

        expect($rejected->status)->toBe(KnowledgeStatus::Rejected->value)
            ->and($rejected->metadata['importance']['final_score'])->toBe(66)
            ->and($judge->calls)->toBe(1);

        // The administrator decides 70 was too strict and turns the dial down. The
        // threshold is deliberately NOT part of the cache identity, so the same
        // knowledge captured again re-derives its verdict from the STORED semantic
        // assessment: no second model call, and the opposite outcome.
        workflowSettings(ImportanceClassifierMode::Enforce, threshold: 60);

        $accepted = storeThroughWriter('Manager approval');
        runClassificationJob((int) $accepted->id);
        $accepted->refresh();

        expect($judge->calls)->toBe(1, 'Moving the threshold re-ran the model.')
            ->and(ImportanceAssessment::query()->count())->toBe(1)
            ->and($accepted->metadata['importance']['cache_hit'])->toBeTrue()
            ->and($accepted->metadata['importance']['semantic_score'])->toBe(55)
            ->and($accepted->metadata['importance']['final_score'])->toBe(66)
            ->and($accepted->metadata['importance']['verdict'])->toBe('important')
            ->and($accepted->metadata['importance']['would_reject'])->toBeFalse()
            ->and($accepted->status)->toBe(KnowledgeStatus::Pending->value);

        // The entry decided under the OLD threshold is untouched by the new one.
        // Both entries share the single assessment row, so re-stamping its verdict
        // on the cache hit would rewrite the audit record of a live rejection: the
        // rejected entry would point at a row reading `important`. The row records
        // the verdict at its FIRST computation and nothing rewrites it; the verdict
        // that actually applied to each entry is the one in its own metadata.
        $rejected->refresh();

        expect($rejected->status)->toBe(KnowledgeStatus::Rejected->value)
            ->and($rejected->metadata['importance']['verdict'])->toBe('not_important')
            ->and($rejected->importance_assessment_id)->toBe($accepted->importance_assessment_id);

        $assessment = ImportanceAssessment::query()->sole();
        expect($assessment->verdict)->toBe(ImportanceVerdict::NotImportant)
            ->and($assessment->semantic_score)->toBe(55)
            ->and($assessment->final_score)->toBe(66);
    });
});

describe('search and indexing across the classifier lifecycle', function () {
    it('keeps a classifying entry out of the index and out of search', function () {
        workflowSettings(ImportanceClassifierMode::Shadow);
        workflowJudge(workflowJudgement(80));

        $entry = storeThroughWriter('Manager approval');

        expect($entry->status)->toBe(KnowledgeStatus::Classifying->value);
        Queue::assertNotPushed(IndexEntryJob::class);

        // Belt and braces: even if something DID index it, the searcher only ever
        // surfaces approved rows, so the status is enforced twice over. Insert the
        // chunk by hand and prove the second gate holds on its own.
        DB::table('chunk_embeddings')->insert([
            'entry_id' => $entry->id,
            'project_id' => 'wf',
            'chunk_index' => 0,
            'content' => WORKFLOW_CONTENT,
            'embedding' => '['.implode(',', $this->vector).']',
        ]);

        expect(searchEntryIds('manager approval shipping label'))->toBe([]);
    });

    it('indexes an entry the moment it is released to pending, and still keeps it out of search', function () {
        workflowSettings(ImportanceClassifierMode::Shadow, threshold: 70);
        workflowJudge(workflowJudgement(80));

        $entry = storeThroughWriter('Manager approval');
        expect(chunkCount((int) $entry->id))->toBe(0);

        runClassificationJob((int) $entry->id);
        $entry->refresh();

        // The `classifying` -> `pending` transition is what schedules the entry's
        // FIRST indexing pass: it left `classifying` with no chunks of its own, so
        // the observer's recovery-index branch is the only thing that will ever
        // index it.
        expect($entry->status)->toBe(KnowledgeStatus::Pending->value);
        Queue::assertPushed(
            IndexEntryJob::class,
            static fn (IndexEntryJob $job): bool => (int) $job->entryId === (int) $entry->id,
        );

        runIndexing((int) $entry->id);

        // Indexed — the embeddings exist, exactly as for any other pending entry —
        // and still not searchable, because pending has never been searchable.
        // Classification changes WHEN an entry is indexed, not what search returns.
        expect(chunkCount((int) $entry->id))->toBeGreaterThan(0)
            ->and(searchEntryIds('manager approval shipping label'))->toBe([]);
    });

    it('makes an approved entry searchable, exactly as before the classifier existed', function () {
        workflowSettings(ImportanceClassifierMode::Shadow, threshold: 70);
        workflowJudge(workflowJudgement(80));

        $entry = storeThroughWriter('Manager approval');
        runClassificationJob((int) $entry->id);
        runIndexing((int) $entry->id);

        $entry->refresh();
        $entry->update(['status' => KnowledgeStatus::Approved->value]);

        $results = (new HybridSearcher)->search('manager approval shipping label', 'wf');

        expect($results)->not->toBeEmpty()
            ->and($results[0]->entryId)->toBe((int) $entry->id)
            ->and($results[0]->title)->toBe('Manager approval')
            ->and($results[0]->matchedBy)->toContain('vector')
            ->and($results[0]->fusionScore)->toBeGreaterThan(0.0);
    });

    it('erases a rejected entry from the index and never returns it', function () {
        workflowSettings(ImportanceClassifierMode::Enforce, threshold: 70);
        workflowJudge(workflowJudgement(40));

        // An approved entry with the same content, so the query genuinely matches
        // something: a search that returns nothing proves nothing about filtering.
        $approved = storeThroughWriter('Manager approval, approved copy', KnowledgeSource::Import);
        runIndexing((int) $approved->id);
        $approved->update(['status' => KnowledgeStatus::Approved->value]);

        $rejected = storeThroughWriter('Manager approval, rejected copy');
        runClassificationJob((int) $rejected->id);
        $rejected->refresh();

        // Rejected straight out of `classifying`, so it was never indexed at all.
        expect($rejected->status)->toBe(KnowledgeStatus::Rejected->value)
            ->and(chunkCount((int) $rejected->id))->toBe(0);

        $found = searchEntryIds('manager approval shipping label');

        expect($found)->toContain((int) $approved->id)
            ->and($found)->not->toContain((int) $rejected->id);

        // And an entry that DID have chunks from an earlier pending life loses them
        // the moment it is rejected — an administrator rejecting an indexed entry
        // must take it out of the index, not merely relabel it.
        $rejected->update(['status' => KnowledgeStatus::Pending->value]);
        runIndexing((int) $rejected->id);
        expect(chunkCount((int) $rejected->id))->toBeGreaterThan(0);

        $rejected->update(['status' => KnowledgeStatus::Rejected->value]);

        expect(chunkCount((int) $rejected->id))->toBe(0)
            ->and(searchEntryIds('manager approval shipping label'))->not->toContain((int) $rejected->id);
    });
});
