<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\RagImportDocumentTool;
use App\Mcp\Tools\RagListProjectsTool;
use App\Mcp\Tools\RagOpenApprovalUiTool;
use App\Mcp\Tools\RagQueryGraphTool;
use App\Mcp\Tools\RagSearchTool;
use App\Mcp\Tools\RagStatusTool;
use App\Mcp\Tools\RagStoreKnowledgeTool;
use App\Mcp\Tools\RagUpdateProjectTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('rag')]
#[Version('1.0.0')]
#[Instructions('RAG knowledge base for the current project. Use rag_status first to check the project language, then rag_store_knowledge to save insights, rag_search to find relevant entries, and rag_query_graph to explore entity relationships.')]
class RagServer extends Server
{
    protected array $tools = [
        RagStatusTool::class,
        RagStoreKnowledgeTool::class,
        RagUpdateProjectTool::class,
        RagSearchTool::class,
        RagQueryGraphTool::class,
        RagImportDocumentTool::class,
        RagOpenApprovalUiTool::class,
        RagListProjectsTool::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
