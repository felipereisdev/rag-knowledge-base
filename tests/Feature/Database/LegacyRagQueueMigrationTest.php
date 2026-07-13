<?php

use App\Jobs\CondenseSessionJob;
use App\Jobs\IndexEntryJob;
use Illuminate\Support\Facades\DB;

it('routes legacy rag jobs from the default queue without moving unrelated jobs', function () {
    $payloadFor = fn (string $jobClass): string => json_encode([
        'displayName' => $jobClass,
        'data' => [
            'commandName' => $jobClass,
            'command' => 'serialized-command',
        ],
    ], JSON_THROW_ON_ERROR);

    $indexJobId = DB::table('jobs')->insertGetId([
        'queue' => 'default',
        'payload' => $payloadFor(IndexEntryJob::class),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);
    $condenseJobId = DB::table('jobs')->insertGetId([
        'queue' => 'default',
        'payload' => $payloadFor(CondenseSessionJob::class),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);
    $unrelatedJobId = DB::table('jobs')->insertGetId([
        'queue' => 'default',
        'payload' => $payloadFor('App\\Jobs\\UnrelatedJob'),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);

    $migrationPath = database_path('migrations/2026_07_13_000002_route_legacy_rag_jobs_to_named_queues.php');

    expect(is_file($migrationPath))->toBeTrue();

    $migration = require $migrationPath;
    $migration->up();

    expect(DB::table('jobs')->where('id', $indexJobId)->value('queue'))->toBe('indexing')
        ->and(DB::table('jobs')->where('id', $condenseJobId)->value('queue'))->toBe('condense')
        ->and(DB::table('jobs')->where('id', $unrelatedJobId)->value('queue'))->toBe('default');

    $migration->down();

    expect(DB::table('jobs')->where('id', $indexJobId)->value('queue'))->toBe('indexing')
        ->and(DB::table('jobs')->where('id', $condenseJobId)->value('queue'))->toBe('condense');
});
