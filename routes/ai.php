<?php

use App\Mcp\Servers\RagServer;
use Laravel\Mcp\Facades\Mcp;

// stdio transport (php artisan mcp:start rag) — for harnesses that spawn a local process
Mcp::local('rag', RagServer::class);

// HTTP (Streamable) transport — for any harness connecting over the network.
// The project-scoped form pins the project in the URL, because a shared HTTP
// server cannot see the client filesystem to infer it. `rag:install` writes the
// scoped URL into each client. The bare route stays for project-less calls
// (e.g. rag_list_projects) and for callers that pass project_id explicitly.
Mcp::web('/mcp/rag/{project}', RagServer::class);
Mcp::web('/mcp/rag', RagServer::class);
