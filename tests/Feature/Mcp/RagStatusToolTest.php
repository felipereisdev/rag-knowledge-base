<?php

use App\Mcp\Servers\RagServer;
use App\Mcp\Tools\RagStatusTool;
use App\Models\ImportanceAssessment;
use App\Models\ImportanceClassifierSetting;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Services\Importance\DeterministicImportanceRules;
use App\Services\Importance\ImportancePrompt;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Ai\Embeddings;

beforeEach(function () {
    // Fake embeddings so the KnowledgeEntryObserver's IndexEntryJob
    // does not hit the embedder sidecar when entries are created.
    // The local-embedder provider is configured for 768 dimensions.
    $fakeVector = array_fill(0, 768, 0.1);
    Embeddings::fake([
        [$fakeVector],
    ]);

    $this->project = Project::create([
        'id' => 'test-project',
        'name' => 'Test Project',
        'root_path' => '/tmp/test-project',
        'language' => 'pt-BR',
    ]);
});

/**
 * @param  array<string, mixed>|null  $importance  the `metadata.importance` block
 *                                                 the classifier job would have written
 */
function statusEntry(string $status, ?array $importance = null, string $title = 'Entry'): KnowledgeEntry
{
    return KnowledgeEntry::create([
        'project_id' => 'test-project',
        'title' => $title,
        'content' => 'Content here',
        'category' => 'insight',
        'status' => $status,
        'metadata' => $importance === null ? [] : ['importance' => $importance],
    ]);
}

function statusAssessment(string $status, string $projectId = 'test-project'): ImportanceAssessment
{
    return ImportanceAssessment::create([
        'project_id' => $projectId,
        'candidate_hash' => hash('sha256', $projectId.$status.str()->random(8)),
        'normalized_candidate' => ['title' => 'x'],
        'model' => (string) config('rag.importance.model'),
        'prompt_version' => ImportancePrompt::VERSION,
        'rules_version' => DeterministicImportanceRules::VERSION,
        'status' => $status,
    ]);
}

it('returns status for an existing project', function () {
    KnowledgeEntry::create([
        'project_id' => $this->project->id,
        'title' => 'Rule 1',
        'content' => 'Content here',
        'category' => 'business-rule',
        'status' => 'approved',
    ]);
    KnowledgeEntry::create([
        'project_id' => $this->project->id,
        'title' => 'Pending rule',
        'content' => 'Pending content',
        'category' => 'insight',
        'status' => 'pending',
    ]);

    $response = RagServer::tool(RagStatusTool::class, [
        'project_id' => 'test-project',
    ]);

    $response->assertOk();
    $response->assertSee('Project: Test Project (test-project)');
    $response->assertSee('Language: pt-BR');
    $response->assertSee('Total: 2');
    $response->assertSee('Approved: 1');
    $response->assertSee('Pending: 1');
    $response->assertSee('Index queue (global): 0 pending, 0 failed; project: 1 approved without chunks');
});

it('reports pending and failed indexing jobs', function () {
    DB::table('jobs')->insert([
        'queue' => 'indexing',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);
    DB::table('failed_jobs')->insert([
        'uuid' => (string) str()->uuid(),
        'connection' => 'database',
        'queue' => 'indexing',
        'payload' => '{}',
        'exception' => 'embedding failed',
        'failed_at' => now(),
    ]);

    $response = RagServer::tool(RagStatusTool::class, [
        'project_id' => 'test-project',
    ]);

    $response->assertOk();
    $response->assertSee('Index queue (global): 1 pending, 1 failed; project: 0 approved without chunks');
});

it('returns a not-found message for an unknown project', function () {
    $response = RagServer::tool(RagStatusTool::class, [
        'project_id' => 'nonexistent',
    ]);

    $response->assertOk();
    $response->assertSee("Project 'nonexistent' not found");
});

it('auto-creates a project from cwd when project_id is omitted', function () {
    $response = RagServer::tool(RagStatusTool::class, [
        'cwd' => '/tmp/auto-created-project',
    ]);

    $response->assertOk();
    $response->assertSee('auto-created-project');

    $this->assertDatabaseHas('projects', [
        'id' => 'auto-created-project',
    ]);
});

it('reports the importance classifier health', function () {
    ImportanceClassifierSetting::query()->findOrFail(1)->update(['mode' => 'shadow', 'threshold' => 65]);

    // Two entries in flight, one of them past the staleness window.
    statusEntry('classifying', title: 'Fresh in flight');
    $stale = statusEntry('classifying', title: 'Stuck in flight');
    DB::table('knowledge_entries')->where('id', $stale->id)->update([
        'updated_at' => now()->subMinutes((int) config('rag.importance.stale_after_minutes') + 5),
    ]);

    // Shadow verdicts: one keep, one reject.
    statusEntry('pending', [
        'mode' => 'shadow',
        'verdict' => 'not_important',
        'would_reject' => true,
        'final_score' => 20,
    ], 'Shadow reject');
    statusEntry('approved', [
        'mode' => 'shadow',
        'verdict' => 'important',
        'would_reject' => false,
        'final_score' => 82,
    ], 'Shadow keep');

    statusAssessment('succeeded');
    statusAssessment('succeeded');
    statusAssessment('failed');

    DB::table('jobs')->insert([
        'queue' => 'classification',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);
    DB::table('failed_jobs')->insert([
        'uuid' => (string) str()->uuid(),
        'connection' => 'classification',
        'queue' => 'classification',
        'payload' => '{}',
        'exception' => 'classification failed',
        'failed_at' => now(),
    ]);

    $response = RagServer::tool(RagStatusTool::class, [
        'project_id' => 'test-project',
    ]);

    $response->assertOk();
    // Existing keys stay exactly as they were.
    $response->assertSee('Total: 4');
    $response->assertSee('Index queue (global): 0 pending, 0 failed; project: 1 approved without chunks');
    // …and the classifier block is added.
    $response->assertSee('Importance classifier: mode shadow, threshold 65');
    $response->assertSee('Model: '.config('rag.importance.model'));
    $response->assertSee('Classifying: 2; stale over 15 min: 1');
    $response->assertSee('Assessments: 2 succeeded, 1 failed');
    $response->assertSee('Shadow verdicts: 1 would keep, 1 would reject');
    $response->assertSee('Classification queue (global): 1 pending, 1 failed');

    $response->assertStructuredContent(function (AssertableJson $json) {
        $json->has('importance_classifier', fn (AssertableJson $classifier) => $classifier
            ->where('mode', 'shadow')
            ->where('threshold', 65)
            ->where('model', (string) config('rag.importance.model'))
            ->where('prompt_version', ImportancePrompt::VERSION)
            ->where('rules_version', DeterministicImportanceRules::VERSION)
            ->where('classifying', 2)
            ->where('stale_classifying', 1)
            ->where('stale_after_minutes', 15)
            ->where('assessments.succeeded', 2)
            ->where('assessments.failed', 1)
            ->where('shadow.would_keep', 1)
            ->where('shadow.would_reject', 1)
            ->where('queue.name', 'classification')
            ->where('queue.pending', 1)
            ->where('queue.failed', 1)
            ->etc()
        )->etc();
    });
});

it('reports the auto-approval dial, what it approved, and what shadow would approve', function () {
    ImportanceClassifierSetting::query()->findOrFail(1)->update([
        'mode' => 'enforce',
        'threshold' => 65,
        'auto_approve_threshold' => 90,
    ]);

    // Approved by the classifier itself, in enforce: nobody read this one.
    statusEntry('approved', [
        'mode' => 'enforce',
        'verdict' => 'important',
        'would_reject' => false,
        'would_approve' => true,
        'auto_approved' => true,
        'final_score' => 94,
    ], 'Auto-approved');

    // Eligible, but classified in shadow: shadow approves nothing, so it counts
    // towards what enforce WOULD approve and not towards what was approved.
    statusEntry('pending', [
        'mode' => 'shadow',
        'verdict' => 'important',
        'would_reject' => false,
        'would_approve' => true,
        'auto_approved' => false,
        'final_score' => 92,
    ], 'Shadow would approve');

    // Important, but not eligible for auto-approval — in neither count.
    statusEntry('pending', [
        'mode' => 'shadow',
        'verdict' => 'important',
        'would_reject' => false,
        'would_approve' => false,
        'auto_approved' => false,
        'final_score' => 74,
    ], 'Shadow keep only');

    $response = RagServer::tool(RagStatusTool::class, ['project_id' => 'test-project']);

    $response->assertOk();
    $response->assertStructuredContent(function (AssertableJson $json) {
        $json->has('importance_classifier', fn (AssertableJson $classifier) => $classifier
            ->where('auto_approve_threshold', 90)
            ->where('auto_approved', 1)
            ->where('shadow_would_approve', 1)
            // Every pre-existing key of this object is a backward-compatibility
            // contract, and none of them moved.
            ->where('mode', 'enforce')
            ->where('threshold', 65)
            ->where('model', (string) config('rag.importance.model'))
            ->where('prompt_version', ImportancePrompt::VERSION)
            ->where('rules_version', DeterministicImportanceRules::VERSION)
            ->has('classifying')
            ->has('stale_classifying')
            ->has('stale_after_minutes')
            ->has('assessments.succeeded')
            ->has('assessments.failed')
            ->has('shadow.would_keep')
            ->has('shadow.would_reject')
            ->has('queue.name')
            ->has('queue.pending')
            ->has('queue.failed')
            ->etc()
        )->etc();
    });
});

it('reports a null auto-approve threshold as auto-approval being off', function () {
    ImportanceClassifierSetting::query()->findOrFail(1)->update(['auto_approve_threshold' => null]);

    $response = RagServer::tool(RagStatusTool::class, ['project_id' => 'test-project']);

    $response->assertOk();
    $response->assertStructuredContent(function (AssertableJson $json) {
        $json->has('importance_classifier', fn (AssertableJson $classifier) => $classifier
            ->where('auto_approve_threshold', null)
            ->where('auto_approved', 0)
            ->etc()
        )->etc();
    });
});

it('counts auto-approved entries of other projects apart', function () {
    Project::create([
        'id' => 'other-project',
        'name' => 'Other Project',
        'root_path' => '/tmp/other-project',
        'language' => 'en',
    ]);

    KnowledgeEntry::create([
        'project_id' => 'other-project',
        'title' => 'Auto-approved elsewhere',
        'content' => 'Content here',
        'category' => 'insight',
        'status' => 'approved',
        'metadata' => ['importance' => [
            'mode' => 'enforce',
            'verdict' => 'important',
            'would_approve' => true,
            'auto_approved' => true,
            'final_score' => 95,
        ]],
    ]);

    $response = RagServer::tool(RagStatusTool::class, ['project_id' => 'test-project']);

    $response->assertOk();
    $response->assertStructuredContent(function (AssertableJson $json) {
        $json->has('importance_classifier', fn (AssertableJson $classifier) => $classifier
            ->where('auto_approved', 0)
            ->where('shadow_would_approve', 0)
            ->etc()
        )->etc();
    });
});

it('excludes enforce rejections from the shadow would-reject count', function () {
    // `would_reject` is written in every mode — it mirrors "the computed verdict
    // was not_important" — so an enforce rejection carries it too. The shadow
    // counters must not swallow it, or the calibration numbers lie.
    statusEntry('rejected', [
        'mode' => 'enforce',
        'verdict' => 'not_important',
        'would_reject' => true,
        'final_score' => 12,
    ], 'Enforced rejection');
    statusEntry('pending', [
        'mode' => 'shadow',
        'verdict' => 'not_important',
        'would_reject' => true,
        'final_score' => 18,
    ], 'Shadow rejection');

    $response = RagServer::tool(RagStatusTool::class, [
        'project_id' => 'test-project',
    ]);

    $response->assertOk();
    $response->assertSee('Shadow verdicts: 0 would keep, 1 would reject');
});

it('counts assessments and classification jobs of other projects and queues apart', function () {
    Project::create([
        'id' => 'other-project',
        'name' => 'Other Project',
        'root_path' => '/tmp/other-project',
        'language' => 'en',
    ]);

    statusAssessment('succeeded', 'other-project');
    statusAssessment('failed', 'other-project');

    // The `classification` queue shares the `jobs` table with the default
    // connection: an indexing job must not be counted as a classification job.
    DB::table('jobs')->insert([
        'queue' => 'indexing',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);

    $response = RagServer::tool(RagStatusTool::class, [
        'project_id' => 'test-project',
    ]);

    $response->assertOk();
    $response->assertSee('Assessments: 0 succeeded, 0 failed');
    $response->assertSee('Classification queue (global): 0 pending, 0 failed');
});

it('keeps the README sample of this block in step with the versions actually shipped', function () {
    // The README prints a sample `rag_status` block with the prompt/rules
    // versions baked in. It drifted once already (it advertised a prompt
    // version that never shipped), and an operator reading it has no way to
    // tell. Pin it to the constants that are the single source of truth.
    $readme = file_get_contents(base_path('README.md'));

    expect($readme)->toContain(
        'Prompt: '.ImportancePrompt::VERSION.' | Rules: '.DeterministicImportanceRules::VERSION,
    );
});
