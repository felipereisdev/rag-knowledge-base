<?php

use App\Exceptions\ProjectNotIdentifiedException;
use App\Mcp\Tools\Concerns\ResolvesProjectId;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

function resolver(): object
{
    return new class
    {
        use ResolvesProjectId;
    };
}

it('prefers an explicit project_id over everything', function () {
    expect(resolver()->resolveProjectId('explicit', '/some/cwd'))->toBe('explicit');
});

it('uses the {project} URL segment when no project_id is given', function () {
    $route = new Route(['POST'], '/mcp/rag/{project}', fn () => null);
    $route->bind(Request::create('/mcp/rag/acme-web', 'POST'));
    request()->setRouteResolver(fn () => $route);

    expect(resolver()->resolveProjectId(null))->toBe('acme-web');
});

it('slugifies the basename of an explicit cwd when nothing else identifies it', function () {
    expect(resolver()->resolveProjectId(null, '/Users/me/Projects/My App'))->toBe('my-app');
});

it('throws ProjectNotIdentifiedException when no project_id, URL segment, or cwd is available', function () {
    expect(fn () => resolver()->resolveProjectId(null))
        ->toThrow(ProjectNotIdentifiedException::class);
});

it('never silently falls back to the server working directory', function () {
    // The whole point of the fix: with no identity, we must NOT resolve to the
    // container's cwd basename (e.g. "public"). It must throw instead.
    expect(fn () => resolver()->resolveProjectId(null, null))
        ->toThrow(ProjectNotIdentifiedException::class);
});
