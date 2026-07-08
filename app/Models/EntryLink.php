<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntryLink extends Model
{
    public $timestamps = false;

    protected $fillable = ['from_entry', 'to_entry', 'relation'];

    /** @return BelongsTo<KnowledgeEntry, $this> */
    public function fromEntry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class, 'from_entry');
    }

    /** @return BelongsTo<KnowledgeEntry, $this> */
    public function toEntry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class, 'to_entry');
    }
}
