<?php

use App\Jobs\CondenseSessionJob;
use App\Jobs\IndexEntryJob;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;

it('routes rag jobs to their dedicated queues', function () {
    $indexEntryJob = new IndexEntryJob(42);
    $condenseSessionJob = new CondenseSessionJob(
        projectId: 'rag',
        transcriptPath: '/tmp/session.jsonl',
        sessionId: 'session-42',
    );

    expect($indexEntryJob->entryId)->toBe(42)
        ->and($indexEntryJob->queue)->toBe('indexing')
        ->and($condenseSessionJob->queue)->toBe('condense');
});

it('deduplicates entry indexing only until processing starts', function () {
    expect(new IndexEntryJob(42))->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class);
});
