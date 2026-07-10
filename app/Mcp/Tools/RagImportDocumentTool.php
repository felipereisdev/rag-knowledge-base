<?php

namespace App\Mcp\Tools;

use App\Exceptions\ProjectNotIdentifiedException;
use App\Mcp\Tools\Concerns\ResolvesProjectId;
use App\Services\Importing\DocumentImporter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('rag_import_document')]
#[Description('Import a markdown (.md) or text (.txt) file into the knowledge base. Markdown files are split by H1/H2 headers into separate entries. Use this when the user has documentation, notes, or rules written in files.')]
class RagImportDocumentTool extends Tool
{
    use ResolvesProjectId;

    public function __construct(
        private readonly DocumentImporter $importer,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            $pid = $this->ensureProject($request->get('project_id'), $request->get('cwd'));
        } catch (ProjectNotIdentifiedException $e) {
            return Response::text($e->getMessage());
        }
        $filePath = (string) $request->get('file_path', '');
        $category = (string) ($request->get('category') ?? 'insight');
        $tags = $request->get('tags');

        if (! is_array($tags)) {
            $tags = null;
        }

        try {
            $entryIds = $this->importer->import($pid, $filePath, $category, $tags);
        } catch (\InvalidArgumentException $e) {
            return Response::text($e->getMessage());
        }

        $text = 'Imported '.count($entryIds)." entries from {$filePath}.\n".
            "  Project: {$pid}\n".
            "  Status: pending (needs approval)\n\n".
            'Approve at '.config('app.url', 'http://localhost:8090').'/martis/resources/knowledge-entries';

        return Response::text($text);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'file_path' => $schema->string()
                ->description('Absolute path to the .md or .txt file to import.')
                ->required(),
            'project_id' => $schema->string()
                ->description('The project ID. If omitted, resolves from the current working directory.'),
            'category' => $schema->string()
                ->description('Category for all imported entries.')
                ->default('insight'),
            'tags' => $schema->array()
                ->items($schema->string())
                ->description('Tags to attach to all imported entries.'),
        ];
    }
}
