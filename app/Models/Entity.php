<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Entity extends Model
{
    public $timestamps = false;

    protected $fillable = ['project_id', 'name', 'type'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** @return BelongsToMany<KnowledgeEntry, $this> */
    public function entries(): BelongsToMany
    {
        return $this->belongsToMany(KnowledgeEntry::class, 'entry_entities', 'entity_id', 'entry_id');
    }

    /** @return HasMany<Relation, $this> */
    public function relationsAsSubject(): HasMany
    {
        return $this->hasMany(Relation::class, 'subject_id');
    }

    /** @return HasMany<Relation, $this> */
    public function relationsAsObject(): HasMany
    {
        return $this->hasMany(Relation::class, 'object_id');
    }
}
