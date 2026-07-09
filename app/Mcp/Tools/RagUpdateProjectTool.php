<?php

namespace App\Mcp\Tools;

use App\Enums\ProjectTechnology;
use App\Exceptions\ProjectNotIdentifiedException;
use App\Mcp\Tools\Concerns\ResolvesProjectId;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('rag_update_project')]
#[Description('Update a project\'s metadata: its tech stack (programming languages and databases), and optionally its description and content language. Writes immediately (no approval workflow — metadata, not knowledge). Inspect the repo to determine which technologies it actually uses, then pass the full authoritative list; this replaces the stored list.')]
class RagUpdateProjectTool extends Tool
{
    use ResolvesProjectId;

    public function handle(Request $request): Response
    {
        try {
            $pid = $this->ensureProject($request->get('project_id'), $request->get('cwd'));
        } catch (ProjectNotIdentifiedException $e) {
            return Response::text($e->getMessage());
        }

        $project = Project::find($pid);
        if (! $project) {
            return Response::text("Project '{$pid}' not found.");
        }

        $changes = [];

        // technologies -> project_type. The column is a plain JSON string (no
        // array cast; Martis MultiSelect owns encode/decode), so encode it here.
        $technologies = $request->get('technologies');
        if ($technologies !== null) {
            [$values, $coerced] = $this->normalizeTechnologies($technologies);
            $project->project_type = json_encode($values);
            $labels = array_map(fn (string $v): string => ProjectTechnology::labelFor($v) ?? $v, $values);
            $line = '  Tech stack: '.($labels !== [] ? implode(', ', $labels) : '(none)');
            if ($coerced !== []) {
                $line .= " — unrecognized coerced to 'other': ".implode(', ', $coerced);
            }
            $changes[] = $line;
        }

        $description = $request->get('description');
        if ($description !== null) {
            $project->description = (string) $description;
            $changes[] = '  Description: '.($project->description !== '' ? $project->description : '(cleared)');
        }

        $language = $request->get('language');
        if ($language !== null) {
            $project->language = (string) $language;
            $changes[] = "  Content language: {$project->language}";
        }

        if ($changes === []) {
            return Response::text("No changes provided. Pass 'technologies', 'description', or 'language'.");
        }

        $project->save();

        return Response::text("Project '{$pid}' updated.\n".implode("\n", $changes));
    }

    /**
     * Normalize a raw technologies input to a deduped list of known values,
     * coercing unrecognized entries to 'other'.
     *
     * @return array{0: list<string>, 1: list<string>} [values, coercedOriginals]
     */
    private function normalizeTechnologies(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [[], []];
        }

        $values = [];
        $coerced = [];
        foreach ($raw as $item) {
            if (! is_string($item) || trim($item) === '') {
                continue;
            }
            $normalized = strtolower(trim($item));
            if (! ProjectTechnology::isValid($normalized)) {
                $coerced[] = $item;
                $normalized = 'other';
            }
            $values[] = $normalized;
        }

        return [array_values(array_unique($values)), array_values(array_unique($coerced))];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'technologies' => $schema->array()
                ->items($schema->string())
                ->description('The project\'s tech stack — programming languages and databases, as lowercase identifiers, e.g. ["python","typescript","postgresql","redis"]. Values are matched against the known catalog (case-insensitive); unrecognized values are coerced to "other". Replaces the stored list.'),
            'description' => $schema->string()
                ->description('Short project description. Optional; only updated when provided.'),
            'language' => $schema->string()
                ->description('Content/locale language for FTS stemming (e.g. en, pt, es). Optional; only updated when provided.'),
            'project_id' => $schema->string()
                ->description('The project ID. If omitted, resolves from the current working directory.'),
        ];
    }
}
