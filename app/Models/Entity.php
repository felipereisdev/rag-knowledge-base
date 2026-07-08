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

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function entries(): BelongsToMany
    {
        return $this->belongsToMany(KnowledgeEntry::class, 'entry_entities', 'entity_id', 'entry_id');
    }

    public function relationsAsSubject(): HasMany
    {
        return $this->hasMany(Relation::class, 'subject_id');
    }

    public function relationsAsObject(): HasMany
    {
        return $this->hasMany(Relation::class, 'object_id');
    }
}