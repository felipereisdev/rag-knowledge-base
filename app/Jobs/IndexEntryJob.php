<?php

namespace App\Jobs;

use App\Models\KnowledgeEntry;
use App\Services\Indexing\EntryIndexer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class IndexEntryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public readonly string $entryId,
    ) {}

    public function handle(EntryIndexer $indexer): void
    {
        $entry = KnowledgeEntry::find($this->entryId);

        if (! $entry) {
            Log::warning('IndexEntryJob: entry not found', ['entry_id' => $this->entryId]);

            return;
        }

        $indexer->index($entry);
    }
}
