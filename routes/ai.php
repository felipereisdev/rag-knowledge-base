<?php

use App\Mcp\Servers\RagServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('rag', RagServer::class);
