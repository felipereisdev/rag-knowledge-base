<?php

use App\Jobs\CondenseSessionJob;
use App\Jobs\IndexEntryJob;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('jobs')
            ->where('queue', 'default')
            ->select(['id', 'payload'])
            ->orderBy('id')
            ->chunkById(100, function ($jobs): void {
                foreach ($jobs as $job) {
                    $payload = json_decode($job->payload, true);
                    $jobClass = is_array($payload)
                        ? ($payload['displayName'] ?? $payload['data']['commandName'] ?? null)
                        : null;
                    $queue = match ($jobClass) {
                        IndexEntryJob::class => 'indexing',
                        CondenseSessionJob::class => 'condense',
                        default => null,
                    };

                    if ($queue !== null) {
                        DB::table('jobs')
                            ->where('id', $job->id)
                            ->where('queue', 'default')
                            ->update(['queue' => $queue]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Intentionally irreversible: named queues also contain post-upgrade jobs.
    }
};
