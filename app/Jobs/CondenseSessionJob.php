<?php

namespace App\Jobs;

use App\Models\CondenseRun;
use App\Models\CondenseSetting;
use App\Services\Condense\CondenseDedup;
use App\Services\Condense\KnowledgeExtractorFactory;
use App\Services\Condense\TranscriptParser;
use App\Services\Knowledge\KnowledgeWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CondenseSessionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $projectId,
        public readonly string $transcriptPath,
        public readonly string $sessionId,
    ) {}

    public function handle(
        TranscriptParser $parser,
        KnowledgeExtractorFactory $factory,
        CondenseDedup $dedup,
        KnowledgeWriter $writer,
    ): void {
        $setting = CondenseSetting::current();
        if (! $setting->enabled) {
            return;
        }

        // Idempotency guard: the unique session_id makes a second job no-op.
        // Wrapped in DB::transaction() so Postgres uses a SAVEPOINT here; without
        // it, catching the unique-violation QueryException still leaves the
        // outer transaction aborted and every later query in this request fails.
        try {
            $run = DB::transaction(fn () => CondenseRun::create([
                'session_id' => $this->sessionId,
                'project_id' => $this->projectId,
                'status' => 'running',
            ]));
        } catch (QueryException) {
            Log::info('CondenseSessionJob: session already processed, skipping', ['session_id' => $this->sessionId]);

            return;
        }

        if (! is_readable($this->transcriptPath)) {
            $run->update(['status' => 'failed']);
            Log::warning('CondenseSessionJob: transcript not readable', ['path' => $this->transcriptPath]);

            return;
        }

        try {
            $text = $parser->parse($this->transcriptPath, $setting->max_transcript_chars);
            if (trim($text) === '') {
                $run->update(['status' => 'skipped']);

                return;
            }

            $candidates = $factory->make($setting)->extract($text);

            // Dedup queries chunk_embeddings, but a candidate written earlier in
            // THIS same loop is indexed asynchronously (IndexEntryJob on the
            // database queue), so its embedding may not be visible to the next
            // candidate's dedup within the same session; cross-session dedup is
            // the design goal.
            $created = 0;
            foreach ($candidates as $c) {
                if ($dedup->isDuplicate($this->projectId, $c['title'], $c['content'], $setting->min_dedup_score)) {
                    continue;
                }
                $writer->store(
                    $this->projectId, $c['title'], $c['content'], $c['category'],
                    'condense', [], $c['entities'], $c['relations'],
                );
                $created++;
            }

            $run->update([
                'status' => $created > 0 ? 'done' : 'skipped',
                'entries_created' => $created,
            ]);
        } catch (Throwable $e) {
            $run->update(['status' => 'failed']);
            Log::warning('CondenseSessionJob: run failed', ['session_id' => $this->sessionId, 'error' => $e->getMessage()]);
        }
    }
}
