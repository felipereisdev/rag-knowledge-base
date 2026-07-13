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
            IndexEntryJob::dispatch((int) $entry->id)->afterCommit();
        }
    }

    public function updated(KnowledgeEntry $entry): void
    {
        if ($entry->status === 'rejected') {
            DB::table('chunk_embeddings')->where('entry_id', $entry->id)->delete();

            return;
        }

        if (! in_array($entry->status, ['approved', 'pending'], true)) {
            return;
        }

        $embeddedContentChanged = $entry->wasChanged(['content', 'project_id']);
        $needsRecoveryIndex = $entry->wasChanged('status') && ! $entry->chunks()->exists();

        if ($embeddedContentChanged || $needsRecoveryIndex) {
            IndexEntryJob::dispatch((int) $entry->id)->afterCommit();
        }
    }

    public function deleted(KnowledgeEntry $entry): void
    {
        DB::table('chunk_embeddings')
            ->where('entry_id', $entry->id)
            ->delete();
    }
}
