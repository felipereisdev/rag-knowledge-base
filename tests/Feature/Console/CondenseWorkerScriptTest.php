<?php

it('ships an executable condense-worker helper with both placement branches', function () {
    $path = base_path('bin/condense-worker.sh');

    expect(file_exists($path))->toBeTrue();
    expect(is_executable($path))->toBeTrue();

    $body = file_get_contents($path);

    // reads the driver from the Martis setting
    expect($body)->toContain('rag:condense-driver');
    // sdk branch: guards on the claude binary, runs the worker on the host
    expect($body)->toContain('command -v claude');
    expect($body)->toContain('queue:work');
    // api branch: brings up the dockerized worker via the profile
    expect($body)->toContain('docker compose --profile condense up -d worker');
});

it('gates the docker worker behind the condense compose profile', function () {
    $compose = file_get_contents(base_path('docker-compose.yml'));

    // the worker service must be opt-in (not auto-started by `docker compose up`)
    expect($compose)->toContain('profiles: ["condense"]');
});
