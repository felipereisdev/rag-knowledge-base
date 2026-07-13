<?php

namespace App\Observers;

use App\Enums\KnowledgeStatus;
use App\Jobs\IndexEntryJob;
use App\Models\KnowledgeEntry;
use Illuminate\Support\Facades\DB;

class KnowledgeEntryObserver
{
    /**
     * Statuses whose content belongs in the index.
     *
     * `classifying` is deliberately absent: the entry is still awaiting its
     * importance verdict, so it must not be searchable (and must not be
     * embedded) until it is released — a verdict of `not_important` under
     * `enforce` never reaches the index at all.
     *
     * @var list<string>
     */
    private const array INDEXED_STATUSES = [
        KnowledgeStatus::Approved->value,
        KnowledgeStatus::Pending->value,
    ];

    /**
     * Statuses an entry can leave with no chunks of its own, so the transition
     * itself is what schedules the first indexing pass.
     *
     * @var list<string>
     */
    private const array UNINDEXED_STATUSES = [
        KnowledgeStatus::Rejected->value,
        KnowledgeStatus::Classifying->value,
    ];

    public function created(KnowledgeEntry $entry): void
    {
        if (in_array($entry->status, self::INDEXED_STATUSES, true)) {
            IndexEntryJob::dispatch((int) $entry->id)->afterCommit();
        }
    }

    public function updated(KnowledgeEntry $entry): void
    {
        if ($entry->status === KnowledgeStatus::Rejected->value) {
            DB::table('chunk_embeddings')->where('entry_id', $entry->id)->delete();

            return;
        }

        if (! in_array($entry->status, self::INDEXED_STATUSES, true)) {
            return;
        }

        $embeddedContentChanged = $entry->wasChanged(['content', 'project_id']);
        $needsRecoveryIndex = $entry->wasChanged('status')
            && in_array($entry->getOriginal('status'), self::UNINDEXED_STATUSES, true)
            && ! $entry->chunks()->exists();

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
