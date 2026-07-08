<?php

namespace App\Models;

use App\Observers\KnowledgeEntryObserver;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeEntry extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        static::observe(KnowledgeEntryObserver::class);
    }

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'project_id', 'title', 'content', 'category', 'source',
        'author', 'status', 'metadata',
    ];

    protected $attributes = [
        'content' => '',
        'category' => 'insight',
        'source' => 'manual',
        'author' => '',
        'status' => 'pending',
        'metadata' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'entry_tags', 'entry_id', 'tag_id');
    }

    /** @return BelongsToMany<Entity, $this> */
    public function entities(): BelongsToMany
    {
        return $this->belongsToMany(Entity::class, 'entry_entities', 'entry_id', 'entity_id');
    }

    /** @return HasMany<ChunkEmbedding, $this> */
    public function chunks(): HasMany
    {
        return $this->hasMany(ChunkEmbedding::class, 'entry_id');
    }
}
