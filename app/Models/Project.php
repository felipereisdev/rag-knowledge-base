<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'name', 'root_path', 'description', 'project_type', 'language',
    ];

    protected $attributes = [
        'description' => '',
        'project_type' => '',
        'language' => 'en',
    ];

    /** @return HasMany<KnowledgeEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(KnowledgeEntry::class, 'project_id');
    }

    /** @return HasMany<ProjectPath, $this> */
    public function paths(): HasMany
    {
        return $this->hasMany(ProjectPath::class, 'project_id');
    }

    /** @return HasMany<Tag, $this> */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class, 'project_id');
    }

    /** @return HasMany<Entity, $this> */
    public function entities(): HasMany
    {
        return $this->hasMany(Entity::class, 'project_id');
    }
}
