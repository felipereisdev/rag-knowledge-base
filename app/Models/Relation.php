<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Relation extends Model
{
    public $timestamps = false;

    protected $fillable = ['project_id', 'subject_id', 'predicate', 'object_id', 'entry_id'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'subject_id');
    }

    public function object(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'object_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class, 'entry_id');
    }
}