<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyHookToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('rag.hooks.token', '');

        // No token configured: open access, matching the project's localhost
        // model (the /mcp/rag endpoint is unauthenticated too). Setting a token
        // is opt-in hardening for networked deployments.
        if ($expected === '') {
            return $next($request);
        }

        if (! hash_equals($expected, (string) ($request->bearerToken() ?? ''))) {
            return response('Unauthorized', 401);
        }

        return $next($request);
    }
}
