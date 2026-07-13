<?php

namespace App\Jobs;

use App\Models\KnowledgeEntry;
use App\Services\Indexing\EntryIndexer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class IndexEntryJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public readonly int|string $entryId;

    public function __construct(int|string $entryId)
    {
        $this->entryId = (int) $entryId;
        $this->onQueue('indexing');
    }

    public function handle(EntryIndexer $indexer): void
    {
        $entry = KnowledgeEntry::find((int) $this->entryId);

        if (! $entry) {
            Log::warning('IndexEntryJob: entry not found', ['entry_id' => $this->entryId]);

            return;
        }

        $indexer->index($entry);
    }

    public function uniqueId(): string
    {
        return (string) $this->entryId;
    }
}
