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
        $provided = (string) ($request->bearerToken() ?? '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            return response('Unauthorized', 401);
        }

        return $next($request);
    }
}
