<?php

namespace App\Observers;

use App\Jobs\IndexEntryJob;
use App\Models\KnowledgeEntry;
use Illuminate\Support\Facades\DB;

class KnowledgeEntryObserver
{
    public function created(KnowledgeEntry $entry): void
    {
        if (in_array($entry->status, ['approved', 'pending'], true)) {
            IndexEntryJob::dispatch($entry->id)->afterCommit();
        }
    }

    public function updated(KnowledgeEntry $entry): void
    {
        if (in_array($entry->status, ['approved', 'pending'], true)) {
            IndexEntryJob::dispatch($entry->id)->afterCommit();
        } elseif ($entry->status === 'rejected') {
            DB::table('chunk_embeddings')
                ->where('entry_id', $entry->id)
                ->delete();
        }
    }

    public function deleted(KnowledgeEntry $entry): void
    {
        DB::table('chunk_embeddings')
            ->where('entry_id', $entry->id)
            ->delete();
    }
}
