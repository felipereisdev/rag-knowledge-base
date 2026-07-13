<?php

use App\Jobs\CondenseSessionJob;
use App\Jobs\IndexEntryJob;

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
