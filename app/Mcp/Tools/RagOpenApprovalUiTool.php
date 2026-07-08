<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesProjectId;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('rag_open_approval_ui')]
#[Description('Open the admin panel to review and approve/reject pending knowledge entries. Call this after storing or importing knowledge so the user can review.')]
class RagOpenApprovalUiTool extends Tool
{
    use ResolvesProjectId;

    public function handle(Request $request): Response
    {
        $pid = $this->resolveProjectId($request->get('project_id'), $request->get('cwd'));
        $base = (string) config('app.url', 'http://127.0.0.1:8000');
        $url = "{$base}/martis/resources/knowledge-entries?filter[status]=pending&filter[project_id]={$pid}";

        return Response::text("Approval UI for project '{$pid}':\n{$url}");
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()
                ->description('The project ID. If omitted, resolves from the current working directory.'),
        ];
    }
}
