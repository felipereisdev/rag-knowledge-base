<?php

namespace App\Services\Knowledge;

use App\Enums\KnowledgeSource;
use App\Jobs\ClassifyKnowledgeEntryJob;
use App\Models\Entity;
use App\Models\ImportanceClassifierSetting;
use App\Models\KnowledgeEntry;
use App\Models\Relation;
use App\Models\Tag;
use App\Services\Importance\KnowledgeIngestionPolicy;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single door into the knowledge base.
 *
 * Every ingestion path — condensation, the MCP tool, the CLI command, the
 * document importer — writes through here, so the question "does this entry get
 * classified, and what status does it start in?" is asked in exactly one place:
 * {@see KnowledgeIngestionPolicy}. No caller decides that for itself, and no
 * caller sets a status.
 *
 * The entry and all of its related records (tags, entities, relations) are
 * written in one transaction, and the classification job is dispatched
 * `afterCommit()`. A worker therefore either sees the whole entry or never sees
 * it at all — it can never pick up an entry whose tags, entities or relations
 * are still in flight (or were rolled back), which would silently change the
 * candidate the classifier judges.
 */
final class KnowledgeWriter
{
    public function __construct(private readonly KnowledgeIngestionPolicy $policy) {}

    /**
     * @param  list<string>  $tags
     * @param  list<array{name:string, type?:string}>  $entities
     * @param  list<array{subject:string, predicate:string, object:string}>  $relations
     *
     * @throws InvalidArgumentException when the source is not a known {@see KnowledgeSource}
     */
    public function store(
        string $projectId,
        string $title,
        string $content,
        string $category,
        KnowledgeSource|string $source,
        array $tags = [],
        array $entities = [],
        array $relations = [],
    ): KnowledgeEntry {
        // Before persistence on purpose: an unknown source has no place in the
        // policy's matrix, and letting it through would quietly store an entry
        // that no mode ever classifies rather than surfacing the bad caller.
        $source = $this->source($source);

        $mode = ImportanceClassifierSetting::current()->mode;
        $status = $this->policy->initialStatus($mode, $source);
        $shouldClassify = $this->policy->shouldClassify($mode, $source);

        return DB::transaction(function () use ($projectId, $title, $content, $category, $source, $status, $shouldClassify, $tags, $entities, $relations) {
            $entry = KnowledgeEntry::create([
                'project_id' => $projectId,
                'title' => $title,
                'content' => $content,
                'category' => $category,
                'source' => $source->value,
                'status' => $status->value,
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

            if ($shouldClassify) {
                // Deferred to the commit: the job reads the entry, its tags,
                // its entities and its relations straight from the database, so
                // it must not be reachable by a worker until every one of them
                // is durable. A rollback discards the dispatch with the write.
                ClassifyKnowledgeEntryJob::dispatch($entry->id)->afterCommit();
            }

            return $entry;
        });
    }

    /**
     * @throws InvalidArgumentException
     */
    private function source(KnowledgeSource|string $source): KnowledgeSource
    {
        if ($source instanceof KnowledgeSource) {
            return $source;
        }

        return KnowledgeSource::tryFrom($source) ?? throw new InvalidArgumentException(
            "Unknown knowledge source [{$source}]. Expected one of: ".implode(', ', KnowledgeSource::values()).'.',
        );
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
