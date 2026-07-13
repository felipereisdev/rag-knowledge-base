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

it('unserializes legacy string entry identifiers', function () {
    $class = IndexEntryJob::class;
    $serialized = sprintf(
        'O:%d:"%s":1:{s:7:"entryId";s:2:"42";}',
        strlen($class),
        $class,
    );

    $job = unserialize($serialized, ['allowed_classes' => [$class]]);

    expect($job)->toBeInstanceOf(IndexEntryJob::class)
        ->and($job->entryId)->toBe('42')
        ->and($job->uniqueId())->toBe('42');
});
