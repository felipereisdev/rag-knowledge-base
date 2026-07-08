<?php

namespace App\Mcp\Tools;

use App\Models\KnowledgeEntry;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('rag_list_projects')]
#[Description('List all projects registered in the knowledge base with their entry counts.')]
class RagListProjectsTool extends Tool
{
    public function handle(Request $request): Response
    {
        $projects = Project::all();

        if ($projects->isEmpty()) {
            return Response::text('No projects registered. Store knowledge to create one.');
        }

        $lines = ['Projects in Knowledge Base:', ''];

        foreach ($projects as $p) {
            $approved = KnowledgeEntry::where('project_id', $p->id)->where('status', 'approved')->count();
            $pending = KnowledgeEntry::where('project_id', $p->id)->where('status', 'pending')->count();
            $chunks = DB::table('chunk_embeddings')->where('project_id', $p->id)->count();

            $lines[] = "  {$p->name} ({$p->id})";
            $lines[] = "    Root: {$p->root_path}";
            $lines[] = "    Language: {$p->language}";
            $lines[] = "    Approved: {$approved} | Pending: {$pending} | Chunks: {$chunks}";
            $lines[] = '';
        }

        return Response::text(implode("\n", $lines));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
