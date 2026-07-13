<?php

it('starts a worker for the queue selected by the container', function () {
    $script = file_get_contents(base_path('docker/entrypoint-worker.sh'));

    expect($script)->toContain('QUEUE_NAME=${QUEUE_NAME:-default}')
        ->and($script)->toContain('--queue="$QUEUE_NAME"')
        ->and($script)->not->toContain('php artisan migrate')
        ->and($script)->not->toContain('queue:work --once');
});
