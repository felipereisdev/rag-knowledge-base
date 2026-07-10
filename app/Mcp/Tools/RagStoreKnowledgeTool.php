<?php

namespace App\Mcp\Tools;

use App\Exceptions\ProjectNotIdentifiedException;
use App\Mcp\Tools\Concerns\ResolvesProjectId;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Services\Knowledge\KnowledgeWriter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
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
        try {
            $pid = $this->ensureProject($request->get('project_id'), $request->get('cwd'));
        } catch (ProjectNotIdentifiedException $e) {
            return Response::text($e->getMessage());
        }
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

        $entry = app(KnowledgeWriter::class)->store(
            $pid, $title, $content, $category, 'mcp', $tags, $entities, $relations,
        );

        $project = Project::find($pid);
        $lang = $project->language ?? 'en';

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
            'Approve at '.config('app.url', 'http://localhost:8090').'/martis/resources/knowledge-entries';

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
                ->items($schema->object([
                    'name' => $schema->string(),
                    'type' => $schema->string(),
                ]))
                ->description('Salient entities mentioned in the content.'),
            'relations' => $schema->array()
                ->items($schema->object([
                    'subject' => $schema->string(),
                    'predicate' => $schema->string(),
                    'object' => $schema->string(),
                ]))
                ->description('Subject-predicate-object triples between entities.'),
        ];
    }
}
