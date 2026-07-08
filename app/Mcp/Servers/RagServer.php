<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\RagImportDocumentTool;
use App\Mcp\Tools\RagQueryGraphTool;
use App\Mcp\Tools\RagSearchTool;
use App\Mcp\Tools\RagStatusTool;
use App\Mcp\Tools\RagStoreKnowledgeTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('rag')]
#[Version('1.0.0')]
#[Instructions('RAG knowledge base for the current project. Use rag_status first to check the project language, then rag_store_knowledge to save insights, rag_search to find relevant entries, and rag_query_graph to explore entity relationships.')]
class RagServer extends Server
{
    // NOTE: Tools are registered incrementally as they are implemented
    // (P3 Tasks 4-9). Registering a class-string that doesn't exist yet
    // breaks ServerContext::tools() resolution, so only list classes
    // that are present. Re-add each tool in its corresponding task.
    protected array $tools = [
        RagStatusTool::class,
        RagStoreKnowledgeTool::class,
        RagSearchTool::class,
        RagQueryGraphTool::class,
        RagImportDocumentTool::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
