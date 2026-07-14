<?php

namespace App\Models;

use App\Observers\KnowledgeEntryObserver;
use Database\Factories\KnowledgeEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $project_id
 * @property string $title
 * @property string $content
 * @property string $category
 * @property string $source
 * @property string $author
 * @property string $status
 * @property array<string, mixed> $metadata
 * @property int|null $importance_assessment_id
 */
class KnowledgeEntry extends Model
{
    /** @use HasFactory<KnowledgeEntryFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::observe(KnowledgeEntryObserver::class);
    }

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

    /** @return BelongsTo<ImportanceAssessment, $this> */
    public function importanceAssessment(): BelongsTo
    {
        return $this->belongsTo(ImportanceAssessment::class, 'importance_assessment_id');
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
