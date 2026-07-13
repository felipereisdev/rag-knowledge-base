<?php

use Illuminate\Support\Str;

it('starts a worker for the queue selected by the container', function () {
    $script = file_get_contents(base_path('docker/entrypoint-worker.sh'));

    expect($script)->toContain('QUEUE_NAME=${QUEUE_NAME:-default}')
        ->and($script)->toContain('--queue="$QUEUE_NAME"')
        ->and($script)->not->toContain('php artisan migrate')
        ->and($script)->not->toContain('queue:work --once');
});

it('runs indexing by default and keeps condensation opt in', function () {
    $compose = file_get_contents(base_path('docker-compose.yml'));
    $override = file_get_contents(base_path('docker-compose.override.yml'));
    $indexer = Str::between($compose, "\n  indexer:\n", "\n  worker:\n");
    $worker = Str::between($compose, "\n  worker:\n", "\n  app-dev:\n");

    expect(preg_match('/^  indexer:\R/m', $compose))->toBe(1)
        ->and($indexer)->toContain('container_name: rag-indexer')
        ->and($indexer)->toContain('QUEUE_NAME=indexing')
        ->and($indexer)->toContain("depends_on:\n      app:")
        ->and($worker)->toContain('profiles: ["condense"]')
        ->and($worker)->toContain('QUEUE_NAME=condense')
        ->and($worker)->toContain("depends_on:\n      app:")
        ->and($compose)->not->toContain('queue:work --once')
        ->and(preg_match('/^  indexer:\R/m', $override))->toBe(1)
        ->and($override)->toContain('RAG_EMBED_URL=http://embedder:8000/v1');
});
