<?php

namespace App\Services\Knowledge;

use App\Models\Entity;
use App\Models\KnowledgeEntry;
use App\Models\Relation;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;

final class KnowledgeWriter
{
    /**
     * @param  list<string>  $tags
     * @param  list<array{name:string, type?:string}>  $entities
     * @param  list<array{subject:string, predicate:string, object:string}>  $relations
     */
    public function store(
        string $projectId,
        string $title,
        string $content,
        string $category,
        string $source,
        array $tags = [],
        array $entities = [],
        array $relations = [],
    ): KnowledgeEntry {
        return DB::transaction(function () use ($projectId, $title, $content, $category, $source, $tags, $entities, $relations) {
            $entry = KnowledgeEntry::create([
                'project_id' => $projectId,
                'title' => $title,
                'content' => $content,
                'category' => $category,
                'source' => $source,
                'status' => 'pending',
            ]);

            foreach ($tags as $tagName) {
                $tag = Tag::firstOrCreate(['project_id' => $projectId, 'name' => $tagName]);
                $entry->tags()->attach($tag->id);
            }

            foreach ($entities as $entityData) {
                // Search on name+type to match the unique constraint and
                // preserve the caller's requested type. Without `type` in the
                // search, an existing entity with a different type would be
                // returned and the requested type silently lost.
                $entity = Entity::firstOrCreate([
                    'project_id' => $projectId,
                    'name' => $entityData['name'],
                    'type' => $entityData['type'] ?? '',
                ]);
                $entry->entities()->attach($entity->id);
            }

            foreach ($relations as $relData) {
                // Relations reference entities by name regardless of type, so
                // look up by name only and fall back to creating with type=''.
                // Using firstOrCreate with only name in the search would risk
                // matching an existing typed entity with the wrong type and
                // then attempting a duplicate insert; an explicit lookup
                // avoids that race.
                $subject = $this->findOrCreateNamedEntity($projectId, $relData['subject']);
                $object = $this->findOrCreateNamedEntity($projectId, $relData['object']);
                Relation::create([
                    'project_id' => $projectId,
                    'subject_id' => $subject->id,
                    'predicate' => $relData['predicate'],
                    'object_id' => $object->id,
                    'entry_id' => $entry->id,
                ]);
            }

            return $entry;
        });
    }

    /**
     * Find an entity by name (regardless of type) or create one with type=''.
     *
     * Relations reference entities by name only, so we look up ignoring type
     * to reuse any existing typed entity. If none exists we create a bare
     * placeholder with an empty type. This avoids the duplicate-key violations
     * that firstOrCreate would trigger when searching on name alone against
     * an existing entity whose type differs from the create values.
     */
    private function findOrCreateNamedEntity(string $projectId, string $name): Entity
    {
        $entity = Entity::where('project_id', $projectId)->where('name', $name)->first();

        return $entity ?? Entity::create(['project_id' => $projectId, 'name' => $name, 'type' => '']);
    }
}
