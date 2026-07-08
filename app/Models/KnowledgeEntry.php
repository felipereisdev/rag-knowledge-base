<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeEntry extends Model
{
    use HasUuids;

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

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'entry_tags', 'entry_id', 'tag_id');
    }

    public function entities(): BelongsToMany
    {
        return $this->belongsToMany(Entity::class, 'entry_entities', 'entry_id', 'entity_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(ChunkEmbedding::class, 'entry_id');
    }
}
