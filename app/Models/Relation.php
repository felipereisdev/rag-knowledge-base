<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Relation extends Model
{
    public $timestamps = false;

    protected $fillable = ['project_id', 'subject_id', 'predicate', 'object_id', 'entry_id'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** @return BelongsTo<Entity, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'subject_id');
    }

    /** @return BelongsTo<Entity, $this> */
    public function object(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'object_id');
    }

    /** @return BelongsTo<KnowledgeEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class, 'entry_id');
    }
}
