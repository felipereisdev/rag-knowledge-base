<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'name', 'root_path', 'description', 'project_type', 'language',
    ];

    protected $attributes = [
        'description' => '',
        'project_type' => '[]',
        'language' => 'en',
    ];

    /**
     * The text columns are NOT NULL with defaults, but Martis writes an empty
     * optional field as NULL — which the DB rejects on update (create is saved
     * by $attributes defaults). Coerce NULL back to the empty sentinel on every
     * write path so no writer can violate the not-null constraint.
     *
     * project_type stays a plain JSON-string column (no array cast): Martis's
     * MultiSelect fill() already encodes to JSON and resolve() decodes it, so a
     * cast here would double-encode. Its empty sentinel is the string '[]'.
     */
    protected static function booted(): void
    {
        static::saving(function (self $project): void {
            $project->description ??= '';
            $project->project_type ??= '[]';
            $project->language ??= 'en';
        });
    }

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
