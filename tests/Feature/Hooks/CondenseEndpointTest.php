<?php

// tests/Feature/Hooks/CondenseEndpointTest.php

use App\Jobs\CondenseSessionJob;
use App\Models\Project;
use Illuminate\Support\Facades\Queue;

it('dispatches a condense job and returns 202', function () {
    Queue::fake();

    $res = $this->postJson('/hooks/condense', [
        'cwd' => '/tmp/acme-app',
        'session_id' => 'sess-9',
        'transcript_path' => '/tmp/acme-app/transcript.jsonl',
    ]);

    $res->assertStatus(202);
    expect(Project::where('id', 'acme-app')->exists())->toBeTrue();
    Queue::assertPushed(CondenseSessionJob::class, fn ($job) => $job->sessionId === 'sess-9'
        && $job->projectId === 'acme-app'
        && $job->transcriptPath === '/tmp/acme-app/transcript.jsonl');
});

it('returns 202 without dispatching when required fields are missing', function () {
    Queue::fake();

    $this->postJson('/hooks/condense', ['cwd' => '/tmp/acme-app'])->assertStatus(202);

    Queue::assertNothingPushed();
});

it('returns 202 without dispatching when the project cannot be resolved', function () {
    Queue::fake();

    $res = $this->postJson('/hooks/condense', [
        'cwd' => '',
        'session_id' => 'sess-9',
        'transcript_path' => '/tmp/acme-app/transcript.jsonl',
    ]);

    $res->assertStatus(202);
    Queue::assertNothingPushed();
});
