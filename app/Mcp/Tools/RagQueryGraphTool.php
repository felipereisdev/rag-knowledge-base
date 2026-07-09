<?php

namespace App\Mcp\Tools;

use App\Exceptions\ProjectNotIdentifiedException;
use App\Mcp\Tools\Concerns\ResolvesProjectId;
use App\Models\Entity;
use App\Models\Project;
use App\Services\Graph\GraphExplorer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('rag_query_graph')]
#[Description('Query the knowledge graph for an entity: show what it is connected to and which indexed knowledge entries mention it. Use this to explore relationships between domain concepts, systems, or rules that vector search alone might not surface.')]
class RagQueryGraphTool extends Tool
{
    use ResolvesProjectId;

    public function __construct(
        private readonly GraphExplorer $explorer,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            $pid = $this->resolveProjectId($request->get('project_id'), $request->get('cwd'));
        } catch (ProjectNotIdentifiedException $e) {
            return Response::text($e->getMessage());
        }
        $project = Project::find($pid);

        if (! $project) {
            return Response::text("Project '{$pid}' not found.");
        }

        $entityName = (string) $request->get('entity', '');
        $depth = (int) ($request->get('depth') ?? 1);

        $result = $this->explorer->explore($pid, $entityName, $depth);

        if ($result['entity'] === null) {
            $top = Entity::where('project_id', $pid)
                ->withCount('entries')
                ->orderByDesc('entries_count')
                ->limit(10)
                ->pluck('name')
                ->all();

            if ($top) {
                return Response::text(
                    "No entity named '{$entityName}' in '{$project->name}'.\n".
                    'Known entities: '.implode(', ', $top)
                );
            }

            return Response::text(
                "No entity named '{$entityName}' in '{$project->name}'.\n".
                "This project's knowledge graph is empty — store entries with entities/relations first."
            );
        }

        $entity = $result['entity'];
        $lines = ['Entity: '.$entity['name'].($entity['type'] !== '' ? " ({$entity['type']})" : '')];
        $lines[] = "Project: {$project->name}";
        $lines[] = '';

        if ($result['relations'] !== []) {
            $lines[] = 'Relations:';
            foreach ($result['relations'] as $r) {
                $lines[] = "  {$r['subject']} —{$r['predicate']}→ {$r['object']}";
            }
        }

        $lines[] = '';
        $lines[] = 'Entities in subgraph: '.implode(', ', array_map(fn ($e) => $e['name'], $result['entities']));

        return Response::text(implode("\n", $lines));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'entity' => $schema->string()
                ->description('The entity name to explore.')
                ->required(),
            'project_id' => $schema->string()
                ->description('The project ID. If omitted, resolves from the current working directory.'),
            'depth' => $schema->integer()
                ->description('Graph traversal depth (1 or 2).')
                ->default(1),
        ];
    }
}
