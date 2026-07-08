<?php

use App\Mcp\Servers\RagServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP Server Registration
|--------------------------------------------------------------------------
|
| The RAG MCP server is exposed as a local (stdio) server so that
| assistants (Claude Code, Cursor, Codex) can invoke the rag_* tools
| via the .mcp.json config at the repo root.
|
*/

Mcp::local('rag', RagServer::class);
