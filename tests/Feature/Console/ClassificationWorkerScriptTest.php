<?php

use App\Jobs\ClassifyKnowledgeEntryJob;

it('ships an executable classification-worker helper', function () {
    $path = base_path('bin/classification-worker.sh');

    expect(file_exists($path))->toBeTrue()
        ->and(is_executable($path))->toBeTrue();
});

it('starts the worker on the dedicated classification connection', function () {
    $body = (string) file_get_contents(base_path('bin/classification-worker.sh'));

    // The leading connection argument is load-bearing: without it the worker
    // reserves the job on the default connection, whose retry_after (90s) is
    // BELOW the job's own timeout (120s), which re-delivers an in-flight
    // classification to a second worker.
    expect($body)->toContain('queue:work classification --queue=classification --tries=3 --timeout=120');

    $timeout = (int) config('rag.importance.timeout') + ClassifyKnowledgeEntryJob::TIMEOUT_MARGIN_SECONDS;
    $retryAfter = (int) config('queue.connections.classification.retry_after');

    expect($timeout)->toBe(120)
        ->and((int) config('rag.importance.timeout'))->toBeLessThan($timeout)
        ->and($timeout)->toBeLessThan($retryAfter);
});

it('fails clearly when claude is not on PATH', function () {
    $body = (string) file_get_contents(base_path('bin/classification-worker.sh'));

    // The classifier shells out to the host's authenticated `claude` CLI; the
    // production image has none, so an absent binary must stop the worker
    // rather than let every job fail open into the review queue.
    expect($body)->toContain('command -v claude')
        ->and($body)->toContain('exit 1');
});

it('resolves the project root from the script location, not the caller cwd', function () {
    $body = (string) file_get_contents(base_path('bin/classification-worker.sh'));

    expect($body)->toContain('dirname -- "$0"')
        ->and($body)->toContain('set -e');
});
