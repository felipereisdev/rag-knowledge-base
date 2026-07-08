<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesProjectId;
use App\Models\Entity;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Models\Relation;
use App\Models\Tag;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('rag_store_knowledge')]
#[Description('Store a knowledge entry in the RAG knowledge base. Use this to save business rules, design decisions, architecture notes, or any insight that would be useful for future conversations about this project. Check the project language with rag_status first. Format content as Markdown. Also extract entities and relations to feed the knowledge graph. Entries go through an approval workflow before becoming searchable.')]
class RagStoreKnowledgeTool extends Tool
{
    use ResolvesProjectId;

    public function handle(Request $request): Response
    {
        $pid = $this->ensureProject($request->get('project_id'), $request->get('cwd'));
        $title = (string) $request->get('title', '');
        $content = (string) $request->get('content', '');
        $category = (string) $request->get('category', 'insight');
        $tags = $this->coerceStringList($request->get('tags'));
        $rawEntities = $request->get('entities', []) ?? [];
        $rawRelations = $request->get('relations', []) ?? [];
        $entities = $this->coerceGraphItems($rawEntities, ['name']);
        $relations = $this->coerceGraphItems($rawRelations, ['subject', 'predicate', 'object']);
        $skipped = (count($rawEntities) - count($entities))
            + (count($rawRelations) - count($relations));

        $entry = DB::transaction(function () use ($pid, $title, $content, $category, $tags, $entities, $relations) {
            $entry = KnowledgeEntry::create([
                'project_id' => $pid,
                'title' => $title,
                'content' => $content,
                'category' => $category,
                'source' => 'mcp',
                'status' => 'pending',
            ]);

            foreach ($tags as $tagName) {
                $tag = Tag::firstOrCreate([
                    'project_id' => $pid,
                    'name' => $tagName,
                ]);
                $entry->tags()->attach($tag->id);
            }

            foreach ($entities as $entityData) {
                // Search on name+type to match the unique constraint and
                // preserve the caller's requested type. Without `type` in the
                // search, an existing entity with a different type would be
                // returned and the requested type silently lost.
                $entity = Entity::firstOrCreate(
                    [
                        'project_id' => $pid,
                        'name' => $entityData['name'],
                        'type' => $entityData['type'] ?? '',
                    ]
                );
                $entry->entities()->attach($entity->id);
            }

            foreach ($relations as $relData) {
                // Relations reference entities by name regardless of type, so
                // look up by name only and fall back to creating with type=''.
                // Using firstOrCreate with only name in the search would risk
                // matching an existing typed entity with the wrong type and
                // then attempting a duplicate insert; an explicit lookup
                // avoids that race.
                $subject = $this->findOrCreateNamedEntity($pid, $relData['subject']);
                $object = $this->findOrCreateNamedEntity($pid, $relData['object']);
                Relation::create([
                    'project_id' => $pid,
                    'subject_id' => $subject->id,
                    'predicate' => $relData['predicate'],
                    'object_id' => $object->id,
                    'entry_id' => $entry->id,
                ]);
            }

            return $entry;
        });

        $project = Project::find($pid);
        $lang = $project?->language ?? 'en';

        $pending = KnowledgeEntry::where('project_id', $pid)->where('status', 'pending')->count();
        $approved = KnowledgeEntry::where('project_id', $pid)->where('status', 'approved')->count();

        $graphLine = '';
        if ($entities || $relations || $skipped > 0) {
            $skippedStr = $skipped > 0 ? " ({$skipped} malformed items skipped)" : '';
            $graphLine = '  Graph: '.count($entities).' entities, '.count($relations)." relations{$skippedStr}\n";
        }

        $text = "Knowledge entry stored (pending approval).\n".
            "  Title: {$title}\n".
            "  Category: {$category}\n".
            '  Tags: '.($tags ? implode(', ', $tags) : '(none)')."\n".
            "  Language: {$lang}\n".
            $graphLine.
            "  ID: {$entry->id}\n\n".
            "Project: {$pid} — {$pending} pending, {$approved} approved\n".
            'Approve at http://127.0.0.1:8000/martis/resources/knowledge-entries';

        return Response::text($text);
    }

    /**
     * @return array<string>
     */
    private function coerceStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $strings[] = $item;
            }
        }

        return $strings;
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
        $entity = Entity::where('project_id', $projectId)
            ->where('name', $name)
            ->first();

        if ($entity) {
            return $entity;
        }

        return Entity::create([
            'project_id' => $projectId,
            'name' => $name,
            'type' => '',
        ]);
    }

    /**
     * @param  array<string>  $requiredKeys
     * @return array<array<string, string>>
     */
    private function coerceGraphItems(mixed $items, array $requiredKeys): array
    {
        if (! is_array($items)) {
            return [];
        }

        $valid = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $ok = true;
            foreach ($requiredKeys as $key) {
                $val = $item[$key] ?? null;
                if (! is_string($val) || trim($val) === '') {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $valid[] = $item;
            }
        }

        return $valid;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()
                ->description('A short, descriptive title for the knowledge entry.')
                ->required(),
            'content' => $schema->string()
                ->description('The knowledge content as Markdown.')
                ->required(),
            'category' => $schema->string()
                ->description('Category for grouping (e.g. business-rule, design-decision, architecture, documentation, insight, convention, constraint).')
                ->default('insight'),
            'tags' => $schema->array()
                ->items($schema->string())
                ->description('Tags to attach to the entry.'),
            'project_id' => $schema->string()
                ->description('The project ID. If omitted, resolves from the current working directory.'),
            'entities' => $schema->array()
                ->items($schema->object()
                    ->property('name', $schema->string())
                    ->property('type', $schema->string()))
                ->description('Salient entities mentioned in the content.'),
            'relations' => $schema->array()
                ->items($schema->object()
                    ->property('subject', $schema->string())
                    ->property('predicate', $schema->string())
                    ->property('object', $schema->string()))
                ->description('Subject-predicate-object triples between entities.'),
        ];
    }
}
