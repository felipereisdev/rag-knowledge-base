<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    public $timestamps = false;

    protected $fillable = ['project_id', 'name'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function entries(): BelongsToMany
    {
        return $this->belongsToMany(KnowledgeEntry::class, 'entry_tags', 'tag_id', 'entry_id');
    }
}