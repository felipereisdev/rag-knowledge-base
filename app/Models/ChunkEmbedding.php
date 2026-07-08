<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChunkEmbedding extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'entry_id', 'project_id', 'chunk_index', 'content', 'embedding',
    ];

    /** @return BelongsTo<KnowledgeEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class, 'entry_id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
