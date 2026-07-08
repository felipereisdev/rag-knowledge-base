<?php

use App\Mcp\Servers\RagServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('rag', RagServer::class);
