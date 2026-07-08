<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    public $timestamps = false;

    protected $fillable = ['project_id', 'name'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** @return BelongsToMany<KnowledgeEntry, $this> */
    public function entries(): BelongsToMany
    {
        return $this->belongsToMany(KnowledgeEntry::class, 'entry_tags', 'tag_id', 'entry_id');
    }
}
