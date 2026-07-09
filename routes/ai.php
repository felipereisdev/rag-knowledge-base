<?php

use App\Mcp\Servers\RagServer;
use Laravel\Mcp\Facades\Mcp;

// stdio transport (php artisan mcp:start rag) — for harnesses that spawn a local process
Mcp::local('rag', RagServer::class);

// HTTP (Streamable) transport — for any harness connecting over the network
Mcp::web('/mcp/rag', RagServer::class);
