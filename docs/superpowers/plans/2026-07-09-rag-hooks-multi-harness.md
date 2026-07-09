# RAG Hooks + Multi-Harness Integration — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a passive knowledge-capture hook layer for the RAG that works across Claude Code, Codex CLI, Cursor, and opencode, plus an idempotent installer that provisions a client project to use the RAG server.

**Architecture:** A shared, token-protected HTTP adapter (`/hooks/*`) reuses the existing `ResolvesProjectId` + `HybridSearcher` services and returns pre-formatted text. Client harnesses consume it through a shared shell core (`rag-core.sh`) with thin per-harness adapters (Claude/Codex/Cursor) and one TS plugin (opencode). A `rag:install` command materializes these artifacts from versioned `stubs/client/` templates into a target project, wiring only the `rag` MCP.

**Tech Stack:** PHP 8 / Laravel 12, Pest 4 (RefreshDatabase, pgsql `rag_test`), POSIX shell + curl, TypeScript (opencode plugin), JSON/TOML config.

## Global Constraints

- Client-installed artifacts wire **only the `rag` MCP** — never martis.
- Hooks must never break a session: every curl uses a short `--max-time` and swallows failures (silent no-op). Only the Stop hook intentionally continues once.
- `RAG_HOOK_INJECT_ON_START` defaults to `false` — SessionStart project-creation always runs, but digest injection is opt-in.
- Endpoints return `text/plain`, ready to inject (no `jq` needed in shell hooks).
- `/hooks/*` requires a bearer token via `hash_equals`; empty configured token → 401.
- The installer is idempotent and non-destructive: merge into existing JSON/TOML, never clobber; re-running is a no-op.
- Default config values: `RAG_HOOK_URL=http://localhost:8080`, `RAG_HOOK_SEARCH_MIN_SCORE=0.40`, `RAG_HOOK_SEARCH_LIMIT=3`, `RAG_HOOK_CONDENSE=true`.
- Follow the existing adapter pattern: logic in Services, thin adapters (Controller/Command) on top. Reuse `HybridSearcher` exactly as `RagSearchTool` does.
- Spec: `docs/superpowers/specs/2026-07-09-rag-hooks-multi-harness-design.md`.

---

## Phase 1 — Backend HTTP adapter

### Task 1: Hook token config, middleware, routing, and `ensure-project` endpoint

**Files:**
- Create: `config/rag.php`
- Create: `app/Http/Middleware/VerifyHookToken.php`
- Create: `app/Http/Controllers/HookController.php`
- Create: `routes/hooks.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/Hooks/EnsureProjectEndpointTest.php`

**Interfaces:**
- Produces: `POST /hooks/ensure-project` (body `{cwd:string}`) → `text/plain` body = resolved `project_id`. Requires `Authorization: Bearer <RAG_HOOK_TOKEN>`.
- Produces: `App\Http\Controllers\HookController` with public methods `ensure(Request)`, `digest(Request)` (Task 2), `search(Request)` (Task 3). The action is named `ensure` (not `ensureProject`) to avoid colliding with the trait's `ensureProject()` method, which would cause infinite recursion.
- Produces: config key `config('rag.hooks.token')`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Hooks/EnsureProjectEndpointTest.php

use App\Models\Project;

function hookHeaders(): array
{
    config()->set('rag.hooks.token', 'test-token');

    return ['Authorization' => 'Bearer test-token'];
}

it('rejects requests without a valid token', function () {
    config()->set('rag.hooks.token', 'test-token');

    $this->postJson('/hooks/ensure-project', ['cwd' => '/tmp/acme'])
        ->assertStatus(401);
});

it('creates the project from cwd and returns its id', function () {
    $res = $this->withHeaders(hookHeaders())
        ->postJson('/hooks/ensure-project', ['cwd' => '/tmp/acme-app']);

    $res->assertOk();
    expect(trim($res->getContent()))->toBe('acme-app');
    expect(Project::where('id', 'acme-app')->exists())->toBeTrue();
});

it('is idempotent for an existing project', function () {
    Project::create(['id' => 'acme-app', 'name' => 'acme-app', 'root_path' => '/tmp/acme-app']);

    $res = $this->withHeaders(hookHeaders())
        ->postJson('/hooks/ensure-project', ['cwd' => '/tmp/acme-app']);

    $res->assertOk();
    expect(Project::where('id', 'acme-app')->count())->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Hooks/EnsureProjectEndpointTest.php`
Expected: FAIL — route `/hooks/ensure-project` not defined (404), not 401/200.

- [ ] **Step 3: Create the config file**

```php
<?php
// config/rag.php

return [
    'hooks' => [
        'token' => env('RAG_HOOK_TOKEN', ''),
    ],
];
```

- [ ] **Step 4: Create the token middleware**

```php
<?php
// app/Http/Middleware/VerifyHookToken.php

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
```

- [ ] **Step 5: Create the controller with `ensureProject`**

```php
<?php
// app/Http/Controllers/HookController.php

namespace App\Http\Controllers;

use App\Mcp\Tools\Concerns\ResolvesProjectId;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class HookController extends Controller
{
    use ResolvesProjectId;

    public function ensure(Request $request): Response
    {
        $cwd = (string) $request->input('cwd', '');
        // Call the trait's ensureProject(): resolves from cwd and creates the Project.
        $pid = $this->ensureProject(null, $cwd !== '' ? $cwd : null);

        return response($pid."\n", 200)->header('Content-Type', 'text/plain');
    }
}
```

Note: `ResolvesProjectId::ensureProject(?string $projectId, ?string $cwd)` slugifies the cwd basename when no `ProjectPath` matches and creates the `Project`. Pass `projectId = null` so it always resolves from `cwd`.

- [ ] **Step 6: Create the routes file**

```php
<?php
// routes/hooks.php

use App\Http\Controllers\HookController;
use App\Http\Middleware\VerifyHookToken;
use Illuminate\Support\Facades\Route;

Route::middleware(VerifyHookToken::class)
    ->prefix('hooks')
    ->group(function () {
        Route::post('ensure-project', [HookController::class, 'ensure']);
        Route::post('digest', [HookController::class, 'digest']);
        Route::post('search', [HookController::class, 'search']);
    });
```

- [ ] **Step 7: Register the routes file in bootstrap/app.php**

Modify the `->withRouting(...)` call to add a `then` closure (add `use Illuminate\Support\Facades\Route;` at the top of the file):

```php
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::group([], base_path('routes/hooks.php'));
        },
    )
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Hooks/EnsureProjectEndpointTest.php`
Expected: PASS (3 passed).

- [ ] **Step 9: Commit**

```bash
git add config/rag.php app/Http/Middleware/VerifyHookToken.php app/Http/Controllers/HookController.php routes/hooks.php bootstrap/app.php tests/Feature/Hooks/EnsureProjectEndpointTest.php
git commit -m "feat: add token-protected /hooks/ensure-project endpoint"
```

---

### Task 2: `digest` endpoint (approved-only compact index)

**Files:**
- Modify: `app/Http/Controllers/HookController.php`
- Test: `tests/Feature/Hooks/DigestEndpointTest.php`

**Interfaces:**
- Consumes: `HookController` + `ResolvesProjectId` (Task 1); `App\Models\KnowledgeEntry` (`status`, `project_id`, `title`, `category`, `tags()`), created with `withoutEvents` to skip the embedding observer.
- Produces: `POST /hooks/digest` (body `{cwd:string, limit?:int}`) → `text/plain`. One line per **approved** entry: `- <title> (<category>)[ · tags: a, b]`. Empty body (no trailing text) when nothing approved. Default limit 20.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Hooks/DigestEndpointTest.php

use App\Models\KnowledgeEntry;
use App\Models\Project;

beforeEach(function () {
    config()->set('rag.hooks.token', 'test-token');
    $this->hdr = ['Authorization' => 'Bearer test-token'];
});

function makeEntry(string $pid, string $title, string $status): void
{
    KnowledgeEntry::withoutEvents(function () use ($pid, $title, $status) {
        KnowledgeEntry::create([
            'project_id' => $pid,
            'title' => $title,
            'content' => 'x',
            'category' => 'insight',
            'status' => $status,
        ]);
    });
}

it('returns only approved entries in the digest', function () {
    Project::create(['id' => 'acme', 'name' => 'acme', 'root_path' => '/tmp/acme']);
    makeEntry('acme', 'Approved rule', 'approved');
    makeEntry('acme', 'Pending rule', 'pending');

    $res = $this->withHeaders($this->hdr)->postJson('/hooks/digest', ['cwd' => '/tmp/acme']);

    $res->assertOk();
    expect($res->getContent())->toContain('Approved rule');
    expect($res->getContent())->not->toContain('Pending rule');
});

it('returns an empty body when nothing is approved', function () {
    Project::create(['id' => 'empty', 'name' => 'empty', 'root_path' => '/tmp/empty']);
    makeEntry('empty', 'Pending only', 'pending');

    $res = $this->withHeaders($this->hdr)->postJson('/hooks/digest', ['cwd' => '/tmp/empty']);

    $res->assertOk();
    expect(trim($res->getContent()))->toBe('');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Hooks/DigestEndpointTest.php`
Expected: FAIL — `HookController::digest` returns null / method error.

- [ ] **Step 3: Implement `digest` on the controller**

Add to `app/Http/Controllers/HookController.php` (add `use App\Models\KnowledgeEntry;`):

```php
    public function digest(Request $request): Response
    {
        $cwd = (string) $request->input('cwd', '');
        $limit = (int) $request->input('limit', 20);
        $pid = $this->resolveProjectId(null, $cwd !== '' ? $cwd : null);

        $entries = KnowledgeEntry::with('tags')
            ->where('project_id', $pid)
            ->where('status', 'approved')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $lines = $entries->map(function (KnowledgeEntry $e): string {
            $tags = $e->tags->pluck('name')->all();
            $tagStr = $tags !== [] ? ' · tags: '.implode(', ', $tags) : '';

            return "- {$e->title} ({$e->category}){$tagStr}";
        })->all();

        return response(implode("\n", $lines), 200)->header('Content-Type', 'text/plain');
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Hooks/DigestEndpointTest.php`
Expected: PASS (2 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/HookController.php tests/Feature/Hooks/DigestEndpointTest.php
git commit -m "feat: add /hooks/digest approved-only index endpoint"
```

---

### Task 3: `search` endpoint (wraps HybridSearcher)

**Files:**
- Modify: `app/Http/Controllers/HookController.php`
- Test: `tests/Feature/Hooks/SearchEndpointTest.php`

**Interfaces:**
- Consumes: `App\Services\Search\HybridSearcher` (constructor `limit:int, minScore:float, expandGraph:bool`; method `search(string $query, string $pid, ?string $category): array` of result objects with `title, category, tags, matchedBy, score, snippet` — same shape `RagSearchTool` uses).
- Produces: `POST /hooks/search` (body `{cwd:string, query:string, limit?:int, min_score?:float}`) → `text/plain` formatted results; empty body when the project is missing, has no approved entries, or no results (so the hook injects nothing).

- [ ] **Step 1: Write the failing test**

We assert the guard/format paths that don't require the embedder. Bind a fake `HybridSearcher` so the "has results" path is deterministic.

```php
<?php
// tests/Feature/Hooks/SearchEndpointTest.php

use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Services\Search\HybridSearcher;

beforeEach(function () {
    config()->set('rag.hooks.token', 'test-token');
    $this->hdr = ['Authorization' => 'Bearer test-token'];
});

it('returns empty body when project has no approved entries', function () {
    Project::create(['id' => 'acme', 'name' => 'acme', 'root_path' => '/tmp/acme']);

    $res = $this->withHeaders($this->hdr)
        ->postJson('/hooks/search', ['cwd' => '/tmp/acme', 'query' => 'anything']);

    $res->assertOk();
    expect(trim($res->getContent()))->toBe('');
});

it('formats results from the searcher', function () {
    Project::create(['id' => 'acme', 'name' => 'acme', 'root_path' => '/tmp/acme']);
    KnowledgeEntry::withoutEvents(fn () => KnowledgeEntry::create([
        'project_id' => 'acme', 'title' => 'A', 'content' => 'x', 'status' => 'approved',
    ]));

    $this->mock(HybridSearcher::class, function ($mock) {
        $mock->shouldReceive('search')->andReturn([
            (object) ['title' => 'Owner scoping', 'category' => 'architecture',
                'tags' => ['auth'], 'matchedBy' => ['vector'], 'score' => 0.91,
                'snippet' => 'Scope by owner_id.'],
        ]);
    });

    $res = $this->withHeaders($this->hdr)
        ->postJson('/hooks/search', ['cwd' => '/tmp/acme', 'query' => 'scoping']);

    $res->assertOk();
    expect($res->getContent())->toContain('Owner scoping')
        ->and($res->getContent())->toContain('score: 0.91');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Hooks/SearchEndpointTest.php`
Expected: FAIL — `HookController::search` missing.

- [ ] **Step 3: Implement `search` on the controller**

Add to `app/Http/Controllers/HookController.php` (add `use App\Models\Project;` and `use App\Services\Search\HybridSearcher;`). Resolve the searcher from the container so the test's mock is honored, then override its request-specific params only when not mocked — simplest is to construct via the container with parameters:

```php
    public function search(Request $request): Response
    {
        $cwd = (string) $request->input('cwd', '');
        $query = (string) $request->input('query', '');
        $limit = (int) $request->input('limit', 3);
        $minScore = (float) $request->input('min_score', 0.40);

        $pid = $this->resolveProjectId(null, $cwd !== '' ? $cwd : null);

        $project = Project::find($pid);
        if (! $project) {
            return $this->plain('');
        }

        $approved = KnowledgeEntry::where('project_id', $pid)->where('status', 'approved')->count();
        if ($approved === 0) {
            return $this->plain('');
        }

        $searcher = app()->makeWith(HybridSearcher::class, [
            'limit' => $limit,
            'minScore' => $minScore,
            'expandGraph' => true,
        ]);

        $results = $searcher->search($query, $pid, $request->input('category'));
        if ($results === []) {
            return $this->plain('');
        }

        $lines = [];
        foreach ($results as $i => $r) {
            $tags = $r->tags !== [] ? ' ['.implode(', ', $r->tags).']' : '';
            $lines[] = '  ['.($i + 1)."] {$r->title} ({$r->category}){$tags} (score: {$r->score})";
            $lines[] = "      {$r->snippet}";
        }

        return $this->plain(implode("\n", $lines));
    }

    private function plain(string $body): Response
    {
        return response($body, 200)->header('Content-Type', 'text/plain');
    }
```

Note: `app()->makeWith(...)` returns the mocked instance in tests (Mockery binds it in the container) and a fresh configured instance in production, mirroring `RagSearchTool`'s per-call construction.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Hooks/SearchEndpointTest.php`
Expected: PASS (2 passed).

- [ ] **Step 5: Run the full backend suite**

Run: `php artisan test tests/Feature/Hooks`
Expected: PASS (all Hooks tests green).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/HookController.php tests/Feature/Hooks/SearchEndpointTest.php
git commit -m "feat: add /hooks/search endpoint wrapping HybridSearcher"
```

---

## Phase 2 — Client hook artifacts (templates under stubs/client/)

Templates use two placeholders substituted by the installer: `__RAG_URL__`, `__RAG_TOKEN__`. Shell scripts are committed with `+x` (git mode 100755).

### Task 4: Shared shell core + config template

**Files:**
- Create: `stubs/client/hooks/lib/rag-core.sh`
- Create: `stubs/client/hooks/config.sh`
- Test: `tests/Feature/Stubs/ClientStubsPresenceTest.php`

**Interfaces:**
- Produces: `rag-core.sh` exposing shell functions `rag_load_config`, `rag_ensure_project <cwd>`, `rag_digest <cwd>`, `rag_search <cwd> <query>`, `rag_condense_instruction`. All read config from a sibling `config.sh` sourced via `$RAG_HOOK_DIR`.
- Produces: `config.sh` defining `RAG_HOOK_URL`, `RAG_HOOK_TOKEN`, `RAG_HOOK_INJECT_ON_START`, `RAG_HOOK_SEARCH_MIN_SCORE`, `RAG_HOOK_SEARCH_LIMIT`, `RAG_HOOK_CONDENSE`.

- [ ] **Step 1: Write the failing test (presence + placeholders)**

```php
<?php
// tests/Feature/Stubs/ClientStubsPresenceTest.php

it('ships the shared shell core and config templates', function () {
    $core = base_path('stubs/client/hooks/lib/rag-core.sh');
    $cfg = base_path('stubs/client/hooks/config.sh');

    expect(file_exists($core))->toBeTrue();
    expect(file_exists($cfg))->toBeTrue();

    $coreSrc = file_get_contents($core);
    expect($coreSrc)->toContain('rag_ensure_project')
        ->and($coreSrc)->toContain('rag_condense_instruction')
        ->and($coreSrc)->toContain('--max-time');

    $cfgSrc = file_get_contents($cfg);
    expect($cfgSrc)->toContain('__RAG_URL__')
        ->and($cfgSrc)->toContain('__RAG_TOKEN__')
        ->and($cfgSrc)->toContain('RAG_HOOK_INJECT_ON_START="false"');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Stubs/ClientStubsPresenceTest.php`
Expected: FAIL — files do not exist.

- [ ] **Step 3: Create `config.sh` template**

```bash
# stubs/client/hooks/config.sh
# RAG hook configuration. Values baked in by `php artisan rag:install`.
RAG_HOOK_URL="__RAG_URL__"
RAG_HOOK_TOKEN="__RAG_TOKEN__"

# Opt-in: inject a digest of approved knowledge at session start.
RAG_HOOK_INJECT_ON_START="false"

# Auto-search tuning.
RAG_HOOK_SEARCH_MIN_SCORE="0.40"
RAG_HOOK_SEARCH_LIMIT="3"

# Enable the end-of-session condensation nudge.
RAG_HOOK_CONDENSE="true"
```

- [ ] **Step 4: Create `rag-core.sh`**

```bash
#!/usr/bin/env sh
# Shared RAG hook core. Sourced by per-harness adapters.
# All network calls fail silently so a hook never breaks a session.

rag_load_config() {
  RAG_HOOK_DIR="${RAG_HOOK_DIR:-$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)}"
  if [ -f "$RAG_HOOK_DIR/config.sh" ]; then
    . "$RAG_HOOK_DIR/config.sh"
  fi
  : "${RAG_HOOK_URL:=http://localhost:8080}"
  : "${RAG_HOOK_TOKEN:=}"
  : "${RAG_HOOK_INJECT_ON_START:=false}"
  : "${RAG_HOOK_SEARCH_MIN_SCORE:=0.40}"
  : "${RAG_HOOK_SEARCH_LIMIT:=3}"
  : "${RAG_HOOK_CONDENSE:=true}"
}

# POST JSON to a /hooks endpoint; echo the text body, or nothing on failure.
_rag_post() {
  _endpoint="$1"
  _body="$2"
  curl -fsS --max-time 4 \
    -H "Authorization: Bearer ${RAG_HOOK_TOKEN}" \
    -H "Content-Type: application/json" \
    -X POST "${RAG_HOOK_URL}/hooks/${_endpoint}" \
    -d "$_body" 2>/dev/null || true
}

# JSON-escape a string value (quotes, backslashes, newlines).
_rag_json_escape() {
  printf '%s' "$1" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))'
}

rag_ensure_project() {
  _cwd=$(_rag_json_escape "$1")
  _rag_post "ensure-project" "{\"cwd\": ${_cwd}}"
}

rag_digest() {
  _cwd=$(_rag_json_escape "$1")
  _rag_post "digest" "{\"cwd\": ${_cwd}}"
}

rag_search() {
  _cwd=$(_rag_json_escape "$1")
  _q=$(_rag_json_escape "$2")
  _rag_post "search" "{\"cwd\": ${_cwd}, \"query\": ${_q}, \"limit\": ${RAG_HOOK_SEARCH_LIMIT}, \"min_score\": ${RAG_HOOK_SEARCH_MIN_SCORE}}"
}

rag_condense_instruction() {
  cat <<'EOF'
Before you finish: judge whether this session produced durable knowledge (a decision, rule, architecture note, non-obvious fix, or convention). If not, stop normally. If yes: first call rag_search to check it is not already stored (dedup); then condense it into one or more knowledge entries — each with a clear title, Markdown content, a category, and any salient entities/relations — and call rag_store_knowledge (it lands in pending for review). Then stop.
EOF
}
```

- [ ] **Step 5: Mark scripts executable**

Run: `chmod +x stubs/client/hooks/lib/rag-core.sh`

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Stubs/ClientStubsPresenceTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add stubs/client/hooks/lib/rag-core.sh stubs/client/hooks/config.sh tests/Feature/Stubs/ClientStubsPresenceTest.php
git commit -m "feat: add shared RAG hook shell core + config template"
```

---

### Task 5: Claude Code adapters + settings/mcp/skill templates

**Files:**
- Create: `stubs/client/claude/hooks/session-start.sh`
- Create: `stubs/client/claude/hooks/user-prompt.sh`
- Create: `stubs/client/claude/hooks/stop.sh`
- Create: `stubs/client/claude/settings.json`
- Create: `stubs/client/claude/mcp.json`
- Create: `stubs/client/claude/skills/using-rag/SKILL.md`
- Test: `tests/Feature/Stubs/ClaudeStubsTest.php`

**Interfaces:**
- Consumes: `rag-core.sh` functions (Task 4).
- Produces: Claude adapters emitting plain stdout (SessionStart/UserPromptSubmit) and `{"decision":"block","reason":...}` guarded by `stop_hook_active` (Stop). `settings.json` wires the three hooks with `$CLAUDE_PROJECT_DIR`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Stubs/ClaudeStubsTest.php

it('ships Claude adapters, settings, mcp, and skill', function () {
    $base = base_path('stubs/client/claude');

    foreach (['hooks/session-start.sh', 'hooks/user-prompt.sh', 'hooks/stop.sh',
              'settings.json', 'mcp.json', 'skills/using-rag/SKILL.md'] as $rel) {
        expect(file_exists("$base/$rel"))->toBeTrue("missing $rel");
    }

    $settings = json_decode(file_get_contents("$base/settings.json"), true);
    expect($settings)->toHaveKey('hooks');
    expect(json_encode($settings))->toContain('SessionStart')
        ->and(json_encode($settings))->toContain('UserPromptSubmit')
        ->and(json_encode($settings))->toContain('Stop');

    $mcp = json_decode(file_get_contents("$base/mcp.json"), true);
    expect($mcp['mcpServers'])->toHaveKey('rag');
    expect($mcp['mcpServers'])->not->toHaveKey('martis');

    expect(file_get_contents("$base/hooks/stop.sh"))->toContain('stop_hook_active');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Stubs/ClaudeStubsTest.php`
Expected: FAIL — files missing.

- [ ] **Step 3: Create `session-start.sh`**

```bash
#!/usr/bin/env sh
# Claude Code SessionStart: ensure project exists; optionally inject digest.
DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
RAG_HOOK_DIR="$DIR"
. "$DIR/../../hooks/lib/rag-core.sh" 2>/dev/null || . "$DIR/lib/rag-core.sh" 2>/dev/null
rag_load_config

CWD="${CLAUDE_PROJECT_DIR:-$(pwd)}"
rag_ensure_project "$CWD" >/dev/null 2>&1

if [ "$RAG_HOOK_INJECT_ON_START" = "true" ]; then
  DIGEST=$(rag_digest "$CWD")
  if [ -n "$DIGEST" ]; then
    printf 'Approved knowledge for this project (search the RAG for details):\n%s\n' "$DIGEST"
  fi
fi
exit 0
```

Note: the installer flattens `lib/` next to each harness's hooks (see Task 10), so `$DIR/lib/rag-core.sh` is the resolved path in an installed client; the first source path is a dev fallback.

- [ ] **Step 4: Create `user-prompt.sh`**

```bash
#!/usr/bin/env sh
# Claude Code UserPromptSubmit: inject relevant RAG hits (stdin is hook JSON).
DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
RAG_HOOK_DIR="$DIR"
. "$DIR/lib/rag-core.sh" 2>/dev/null
rag_load_config

INPUT=$(cat)
PROMPT=$(printf '%s' "$INPUT" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("prompt",""))' 2>/dev/null)
CWD="${CLAUDE_PROJECT_DIR:-$(pwd)}"

# Skip very short prompts to reduce noise.
[ "${#PROMPT}" -lt 8 ] && exit 0

HITS=$(rag_search "$CWD" "$PROMPT")
if [ -n "$HITS" ]; then
  printf 'Relevant prior knowledge (from RAG):\n%s\n' "$HITS"
fi
exit 0
```

- [ ] **Step 5: Create `stop.sh`**

```bash
#!/usr/bin/env sh
# Claude Code Stop: nudge the current session to condense (once).
DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
RAG_HOOK_DIR="$DIR"
. "$DIR/lib/rag-core.sh" 2>/dev/null
rag_load_config

[ "$RAG_HOOK_CONDENSE" != "true" ] && exit 0

INPUT=$(cat)
ACTIVE=$(printf '%s' "$INPUT" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("stop_hook_active", False))' 2>/dev/null)
# Loop guard: if we already blocked once this turn, let it stop.
[ "$ACTIVE" = "True" ] && exit 0

REASON=$(rag_condense_instruction)
printf '%s' "$INPUT" >/dev/null
python3 -c 'import json,sys; print(json.dumps({"decision":"block","reason":sys.argv[1]}))' "$REASON"
exit 0
```

- [ ] **Step 6: Create `settings.json`**

```json
{
  "hooks": {
    "SessionStart": [
      { "hooks": [{ "type": "command", "command": "$CLAUDE_PROJECT_DIR/.claude/hooks/session-start.sh" }] }
    ],
    "UserPromptSubmit": [
      { "hooks": [{ "type": "command", "command": "$CLAUDE_PROJECT_DIR/.claude/hooks/user-prompt.sh" }] }
    ],
    "Stop": [
      { "hooks": [{ "type": "command", "command": "$CLAUDE_PROJECT_DIR/.claude/hooks/stop.sh" }] }
    ]
  }
}
```

- [ ] **Step 7: Create `mcp.json`**

```json
{
  "mcpServers": {
    "rag": { "type": "http", "url": "__RAG_URL__/mcp/rag" }
  }
}
```

- [ ] **Step 8: Create `skills/using-rag/SKILL.md`**

```markdown
---
name: using-rag
description: Use when working in a project wired to the RAG knowledge base — search before answering questions about rules/architecture/decisions, and store durable knowledge. Capture is automated via hooks; you mainly search and review.
---

# Using the RAG knowledge base

This project is wired to a RAG knowledge base over MCP (`rag_*` tools) and
lifecycle hooks.

## When to search (do this first)
Before answering anything about business rules, architecture, past decisions, or
conventions, call `rag_search`. The `UserPromptSubmit` hook may already have
injected hits — treat those as a starting point and search for detail.

## When to store
When you establish a durable fact — a decision, rule, non-obvious fix, or
convention — call `rag_store_knowledge`. It lands in a **pending** approval
queue; it is not searchable until a human approves it in the admin UI.

## Automatic capture
At the end of a session the `Stop` hook asks you to condense the session's
durable knowledge and store it (with entities/relations for the graph). Honor
that: dedup with `rag_search` first, then store concise entries. If nothing
durable happened, just stop.

## Project resolution
The project is resolved from the working directory automatically — you rarely
pass `project_id` explicitly.
```

- [ ] **Step 9: Mark scripts executable**

Run: `chmod +x stubs/client/claude/hooks/session-start.sh stubs/client/claude/hooks/user-prompt.sh stubs/client/claude/hooks/stop.sh`

- [ ] **Step 10: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Stubs/ClaudeStubsTest.php`
Expected: PASS.

- [ ] **Step 11: Commit**

```bash
git add stubs/client/claude tests/Feature/Stubs/ClaudeStubsTest.php
git commit -m "feat: add Claude Code client hook + skill templates"
```

---

### Task 6: Codex CLI adapters + config templates

**Files:**
- Create: `stubs/client/codex/hooks/session-start.sh`
- Create: `stubs/client/codex/hooks/user-prompt.sh`
- Create: `stubs/client/codex/hooks/stop.sh`
- Create: `stubs/client/codex/hooks.json`
- Create: `stubs/client/codex/config.toml.snippet`
- Test: `tests/Feature/Stubs/CodexStubsTest.php`

**Interfaces:**
- Consumes: `rag-core.sh` (Task 4).
- Produces: Codex adapters. SessionStart/UserPromptSubmit emit `{"hookSpecificOutput":{"hookEventName":"<name>","additionalContext":"..."}}`. Stop emits `{"decision":"block","reason":...}` guarded by `stop_hook_active`. `hooks.json` wires `SessionStart`/`UserPromptSubmit`/`Stop`. `config.toml.snippet` provides `[mcp_servers.rag]`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Stubs/CodexStubsTest.php

it('ships Codex adapters, hooks.json, and mcp snippet', function () {
    $base = base_path('stubs/client/codex');

    foreach (['hooks/session-start.sh', 'hooks/user-prompt.sh', 'hooks/stop.sh',
              'hooks.json', 'config.toml.snippet'] as $rel) {
        expect(file_exists("$base/$rel"))->toBeTrue("missing $rel");
    }

    $hooks = json_decode(file_get_contents("$base/hooks.json"), true);
    expect(json_encode($hooks))->toContain('SessionStart')
        ->and(json_encode($hooks))->toContain('UserPromptSubmit')
        ->and(json_encode($hooks))->toContain('Stop');

    $toml = file_get_contents("$base/config.toml.snippet");
    expect($toml)->toContain('[mcp_servers.rag]')
        ->and($toml)->not->toContain('martis');

    expect(file_get_contents("$base/hooks/user-prompt.sh"))->toContain('additionalContext');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Stubs/CodexStubsTest.php`
Expected: FAIL — files missing.

- [ ] **Step 3: Create `session-start.sh`**

```bash
#!/usr/bin/env sh
# Codex SessionStart: ensure project; optionally inject digest as additionalContext.
DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
RAG_HOOK_DIR="$DIR"
. "$DIR/lib/rag-core.sh" 2>/dev/null
rag_load_config

INPUT=$(cat)
CWD=$(printf '%s' "$INPUT" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("cwd",""))' 2>/dev/null)
[ -z "$CWD" ] && CWD="$(pwd)"

rag_ensure_project "$CWD" >/dev/null 2>&1

[ "$RAG_HOOK_INJECT_ON_START" != "true" ] && exit 0
DIGEST=$(rag_digest "$CWD")
[ -z "$DIGEST" ] && exit 0
python3 -c 'import json,sys; print(json.dumps({"hookSpecificOutput":{"hookEventName":"SessionStart","additionalContext":"Approved knowledge:\n"+sys.argv[1]}}))' "$DIGEST"
exit 0
```

- [ ] **Step 4: Create `user-prompt.sh`**

```bash
#!/usr/bin/env sh
# Codex UserPromptSubmit: inject RAG hits as additionalContext.
DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
RAG_HOOK_DIR="$DIR"
. "$DIR/lib/rag-core.sh" 2>/dev/null
rag_load_config

INPUT=$(cat)
PROMPT=$(printf '%s' "$INPUT" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("prompt",""))' 2>/dev/null)
CWD=$(printf '%s' "$INPUT" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("cwd",""))' 2>/dev/null)
[ -z "$CWD" ] && CWD="$(pwd)"
[ "${#PROMPT}" -lt 8 ] && exit 0

HITS=$(rag_search "$CWD" "$PROMPT")
[ -z "$HITS" ] && exit 0
python3 -c 'import json,sys; print(json.dumps({"hookSpecificOutput":{"hookEventName":"UserPromptSubmit","additionalContext":"Relevant prior knowledge (RAG):\n"+sys.argv[1]}}))' "$HITS"
exit 0
```

- [ ] **Step 5: Create `stop.sh`**

```bash
#!/usr/bin/env sh
# Codex Stop: nudge condensation once (same envelope as Claude).
DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
RAG_HOOK_DIR="$DIR"
. "$DIR/lib/rag-core.sh" 2>/dev/null
rag_load_config

[ "$RAG_HOOK_CONDENSE" != "true" ] && exit 0
INPUT=$(cat)
ACTIVE=$(printf '%s' "$INPUT" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("stop_hook_active", False))' 2>/dev/null)
[ "$ACTIVE" = "True" ] && exit 0

REASON=$(rag_condense_instruction)
python3 -c 'import json,sys; print(json.dumps({"decision":"block","reason":sys.argv[1]}))' "$REASON"
exit 0
```

- [ ] **Step 6: Create `hooks.json`**

```json
{
  "SessionStart": [
    { "hooks": [{ "type": "command", "command": ".codex/hooks/session-start.sh" }] }
  ],
  "UserPromptSubmit": [
    { "hooks": [{ "type": "command", "command": ".codex/hooks/user-prompt.sh" }] }
  ],
  "Stop": [
    { "hooks": [{ "type": "command", "command": ".codex/hooks/stop.sh" }] }
  ]
}
```

- [ ] **Step 7: Create `config.toml.snippet`**

```toml
[mcp_servers.rag]
url = "__RAG_URL__/mcp/rag"
bearer_token_env_var = "RAG_MCP_TOKEN"
```

- [ ] **Step 8: Mark scripts executable**

Run: `chmod +x stubs/client/codex/hooks/session-start.sh stubs/client/codex/hooks/user-prompt.sh stubs/client/codex/hooks/stop.sh`

- [ ] **Step 9: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Stubs/CodexStubsTest.php`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add stubs/client/codex tests/Feature/Stubs/CodexStubsTest.php
git commit -m "feat: add Codex CLI client hook + config templates"
```

---

### Task 7: Cursor adapters + hooks/mcp/rules templates (no per-prompt inject)

**Files:**
- Create: `stubs/client/cursor/hooks/session-start.sh`
- Create: `stubs/client/cursor/hooks/stop.sh`
- Create: `stubs/client/cursor/hooks.json`
- Create: `stubs/client/cursor/mcp.json`
- Create: `stubs/client/cursor/cursorrules`
- Test: `tests/Feature/Stubs/CursorStubsTest.php`

**Interfaces:**
- Consumes: `rag-core.sh` (Task 4).
- Produces: Cursor `sessionStart` emitting `{"additional_context":"..."}`; `stop` emitting `{"followup_message":"..."}` guarded by `loop_count`. No `beforeSubmitPrompt` (platform can't inject). `hooks.json` uses `version:1` schema with `loop_limit` on stop.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Stubs/CursorStubsTest.php

it('ships Cursor session-start + stop hooks, config, mcp, rules', function () {
    $base = base_path('stubs/client/cursor');

    foreach (['hooks/session-start.sh', 'hooks/stop.sh', 'hooks.json', 'mcp.json', 'cursorrules'] as $rel) {
        expect(file_exists("$base/$rel"))->toBeTrue("missing $rel");
    }
    // No per-prompt hook on Cursor.
    expect(file_exists("$base/hooks/user-prompt.sh"))->toBeFalse();

    $hooks = json_decode(file_get_contents("$base/hooks.json"), true);
    expect($hooks['version'])->toBe(1);
    expect($hooks['hooks'])->toHaveKey('sessionStart');
    expect($hooks['hooks'])->toHaveKey('stop');

    $mcp = json_decode(file_get_contents("$base/mcp.json"), true);
    expect($mcp['mcpServers'])->toHaveKey('rag')->and($mcp['mcpServers'])->not->toHaveKey('martis');

    expect(file_get_contents("$base/hooks/session-start.sh"))->toContain('additional_context');
    expect(file_get_contents("$base/hooks/stop.sh"))->toContain('followup_message');
    // Stale Python reference must not reappear.
    expect(file_get_contents("$base/cursorrules"))->not->toContain('rag/server/main.py');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Stubs/CursorStubsTest.php`
Expected: FAIL — files missing.

- [ ] **Step 3: Create `session-start.sh`**

```bash
#!/usr/bin/env sh
# Cursor sessionStart: ensure project; optionally inject digest as additional_context.
DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
RAG_HOOK_DIR="$DIR"
. "$DIR/lib/rag-core.sh" 2>/dev/null
rag_load_config

CWD="${CURSOR_PROJECT_DIR:-$(pwd)}"
rag_ensure_project "$CWD" >/dev/null 2>&1

if [ "$RAG_HOOK_INJECT_ON_START" = "true" ]; then
  DIGEST=$(rag_digest "$CWD")
  if [ -n "$DIGEST" ]; then
    python3 -c 'import json,sys; print(json.dumps({"additional_context":"Approved knowledge:\n"+sys.argv[1]}))' "$DIGEST"
    exit 0
  fi
fi
echo '{}'
exit 0
```

- [ ] **Step 4: Create `stop.sh`**

```bash
#!/usr/bin/env sh
# Cursor stop: auto-submit a condensation follow-up once (loop_count guard).
DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
RAG_HOOK_DIR="$DIR"
. "$DIR/lib/rag-core.sh" 2>/dev/null
rag_load_config

if [ "$RAG_HOOK_CONDENSE" != "true" ]; then echo '{}'; exit 0; fi

INPUT=$(cat)
LOOP=$(printf '%s' "$INPUT" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("loop_count",0))' 2>/dev/null)
[ -z "$LOOP" ] && LOOP=0
if [ "$LOOP" -ge 1 ]; then echo '{}'; exit 0; fi

REASON=$(rag_condense_instruction)
python3 -c 'import json,sys; print(json.dumps({"followup_message":sys.argv[1]}))' "$REASON"
exit 0
```

- [ ] **Step 5: Create `hooks.json`**

```json
{
  "version": 1,
  "hooks": {
    "sessionStart": [{ "command": "./.cursor/hooks/session-start.sh" }],
    "stop": [{ "command": "./.cursor/hooks/stop.sh", "loop_limit": 2 }]
  }
}
```

- [ ] **Step 6: Create `mcp.json`**

```json
{
  "mcpServers": {
    "rag": { "url": "__RAG_URL__/mcp/rag" }
  }
}
```

- [ ] **Step 7: Create `cursorrules`**

```
# RAG Knowledge Base
#
# This project is wired to a RAG knowledge base over MCP (rag_* tools) and Cursor hooks.
#
# - Before answering questions about business rules, architecture, or past decisions,
#   call rag_search first.
# - When you establish a durable decision/rule/convention, call rag_store_knowledge
#   (it goes to a pending approval queue; a human approves it in the admin UI).
# - The sessionStart hook can inject a digest of approved knowledge (opt-in), and the
#   stop hook asks you to condense the session before finishing.
# - Project is resolved from the working directory; you rarely pass project_id.
```

- [ ] **Step 8: Mark scripts executable**

Run: `chmod +x stubs/client/cursor/hooks/session-start.sh stubs/client/cursor/hooks/stop.sh`

- [ ] **Step 9: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Stubs/CursorStubsTest.php`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add stubs/client/cursor tests/Feature/Stubs/CursorStubsTest.php
git commit -m "feat: add Cursor client hook + rules templates"
```

---

### Task 8: opencode TS plugin + mcp snippet

**Files:**
- Create: `stubs/client/opencode/plugin/rag.ts`
- Create: `stubs/client/opencode/mcp.snippet.json`
- Test: `tests/Feature/Stubs/OpencodeStubsTest.php`

**Interfaces:**
- Produces: a `Plugin` calling the same `/hooks/*` endpoints via `fetch`. `session.created` → ensure-project (+ optional digest via `experimental.chat.system.transform`); `chat.message` → append search hits to `output.parts`; `session.idle` → one-shot `client.session.prompt` with the condense instruction (guarded by a session-keyed `Set`).
- Produces: `mcp.snippet.json` = `{ "mcp": { "rag": { "type": "remote", "url": "__RAG_URL__/mcp/rag", "enabled": true } } }`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Stubs/OpencodeStubsTest.php

it('ships the opencode plugin and mcp snippet', function () {
    $base = base_path('stubs/client/opencode');
    expect(file_exists("$base/plugin/rag.ts"))->toBeTrue();
    expect(file_exists("$base/mcp.snippet.json"))->toBeTrue();

    $ts = file_get_contents("$base/plugin/rag.ts");
    expect($ts)->toContain('session.idle')
        ->and($ts)->toContain('chat.message')
        ->and($ts)->toContain('/hooks/search')
        ->and($ts)->toContain('__RAG_URL__');

    $mcp = json_decode(file_get_contents("$base/mcp.snippet.json"), true);
    expect($mcp['mcp'])->toHaveKey('rag')->and($mcp['mcp'])->not->toHaveKey('martis');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Stubs/OpencodeStubsTest.php`
Expected: FAIL — files missing.

- [ ] **Step 3: Create `plugin/rag.ts`**

```typescript
// stubs/client/opencode/plugin/rag.ts
// RAG memory plugin for opencode. Talks to the RAG server's /hooks/* endpoints.
import type { Plugin } from "@opencode-ai/plugin"

const RAG_URL = "__RAG_URL__"
const RAG_TOKEN = "__RAG_TOKEN__"
const INJECT_ON_START = false // set true to inject the approved-knowledge digest
const CONDENSE = true

async function ragPost(endpoint: string, body: unknown): Promise<string> {
  try {
    const res = await fetch(`${RAG_URL}/hooks/${endpoint}`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Authorization: `Bearer ${RAG_TOKEN}` },
      body: JSON.stringify(body),
      signal: AbortSignal.timeout(4000),
    })
    if (!res.ok) return ""
    return await res.text()
  } catch {
    return ""
  }
}

const CONDENSE_INSTRUCTION =
  "Before you finish: judge whether this session produced durable knowledge (a decision, rule, architecture note, non-obvious fix, or convention). If not, stop. If yes: call rag_search to dedup, then condense into one or more entries (title, Markdown content, category, entities/relations) and call rag_store_knowledge (it lands in pending)."

export const RagMemory: Plugin = async ({ client, directory }) => {
  const condensed = new Set<string>()

  return {
    event: async ({ event }) => {
      if (event.type === "session.created") {
        await ragPost("ensure-project", { cwd: directory })
      }
      if (CONDENSE && event.type === "session.idle") {
        const id = (event as any).properties?.sessionID
        if (id && !condensed.has(id)) {
          condensed.add(id)
          await client.session.prompt({
            path: { id },
            body: { parts: [{ type: "text", text: CONDENSE_INSTRUCTION }] },
          })
        }
      }
    },

    "chat.message": async (_input, output) => {
      const text = (output.parts ?? [])
        .map((p: any) => (p.type === "text" ? p.text : ""))
        .join(" ")
        .trim()
      if (text.length < 8) return
      const hits = await ragPost("search", { cwd: directory, query: text })
      if (hits) {
        output.parts.push({ type: "text", text: `\n\n[RAG] Relevant prior knowledge:\n${hits}` })
      }
    },

    "experimental.chat.system.transform": async (_input, output) => {
      if (!INJECT_ON_START) return
      const digest = await ragPost("digest", { cwd: directory })
      if (digest) output.system.push(`<rag-approved-knowledge>\n${digest}\n</rag-approved-knowledge>`)
    },
  }
}
```

- [ ] **Step 4: Create `mcp.snippet.json`**

```json
{
  "mcp": {
    "rag": { "type": "remote", "url": "__RAG_URL__/mcp/rag", "enabled": true }
  }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Stubs/OpencodeStubsTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add stubs/client/opencode tests/Feature/Stubs/OpencodeStubsTest.php
git commit -m "feat: add opencode RAG memory plugin template"
```

---

### Task 9: Shared AGENTS.md RAG section template

**Files:**
- Create: `stubs/client/AGENTS.rag.md`
- Test: `tests/Feature/Stubs/AgentsTemplateTest.php`

**Interfaces:**
- Produces: a self-contained Markdown block (Codex/others append it to their `AGENTS.md`) delimited by idempotency markers `<!-- rag:begin -->` / `<!-- rag:end -->`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Stubs/AgentsTemplateTest.php

it('ships an AGENTS.md RAG section with idempotency markers', function () {
    $f = base_path('stubs/client/AGENTS.rag.md');
    expect(file_exists($f))->toBeTrue();
    $src = file_get_contents($f);
    expect($src)->toContain('<!-- rag:begin -->')
        ->and($src)->toContain('<!-- rag:end -->')
        ->and($src)->toContain('rag_search')
        ->and($src)->toContain('rag_store_knowledge');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Stubs/AgentsTemplateTest.php`
Expected: FAIL — file missing.

- [ ] **Step 3: Create `AGENTS.rag.md`**

```markdown
<!-- rag:begin -->
## RAG Knowledge Base

This project is wired to a RAG knowledge base over MCP (`rag_*` tools) and
lifecycle hooks.

- **Search first:** before answering about business rules, architecture, or past
  decisions, call `rag_search`.
- **Store durable knowledge:** when you establish a decision/rule/convention,
  call `rag_store_knowledge`. Entries go to a **pending** approval queue and are
  not searchable until approved in the admin UI.
- **End-of-session capture:** the stop hook asks you to condense the session's
  durable knowledge and store it (dedup with `rag_search` first).
- The project is resolved from the working directory; you rarely pass `project_id`.
<!-- rag:end -->
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Stubs/AgentsTemplateTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add stubs/client/AGENTS.rag.md tests/Feature/Stubs/AgentsTemplateTest.php
git commit -m "feat: add shared AGENTS.md RAG section template"
```

---

## Phase 3 — Installer command

### Task 10: `ClientInstaller` service — copy hooks + config, JSON/text merge helpers

**Files:**
- Create: `app/Services/Install/ClientInstaller.php`
- Test: `tests/Feature/Install/ClientInstallerTest.php`

**Interfaces:**
- Produces: `App\Services\Install\ClientInstaller` with:
  - `__construct(string $stubsRoot)` — defaults to `base_path('stubs/client')`.
  - `install(string $target, array $harnesses, string $url, string $token): array` — returns a list of written/merged relative paths. `$harnesses` ⊆ `['claude','codex','cursor','opencode']`.
  - `mergeJsonFile(string $path, array $incoming): void` — deep-merge, create if absent.
  - `appendMarkedBlock(string $path, string $block): void` — append `<!-- rag:begin -->…<!-- rag:end -->` only if the markers aren't already present.
  - `substitute(string $contents, string $url, string $token): string` — replace `__RAG_URL__`/`__RAG_TOKEN__`.
- Produces: after install, each harness has `lib/rag-core.sh` + `config.sh` next to its hooks (the shared core is flattened into each harness hook dir).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Install/ClientInstallerTest.php

use App\Services\Install\ClientInstaller;
use Illuminate\Support\Facades\File;

function tmpTarget(): string
{
    $dir = sys_get_temp_dir().'/rag-install-'.bin2hex(random_bytes(4));
    File::makeDirectory($dir, 0777, true);

    return $dir;
}

it('substitutes placeholders', function () {
    $installer = new ClientInstaller(base_path('stubs/client'));
    $out = $installer->substitute('url=__RAG_URL__ token=__RAG_TOKEN__', 'http://x:8080', 'secret');
    expect($out)->toBe('url=http://x:8080 token=secret');
});

it('installs Claude artifacts with substituted config and rag-only mcp', function () {
    $target = tmpTarget();
    $installer = new ClientInstaller(base_path('stubs/client'));

    $installer->install($target, ['claude'], 'http://localhost:8080', 'tok123');

    expect(File::exists("$target/.claude/hooks/session-start.sh"))->toBeTrue();
    expect(File::exists("$target/.claude/hooks/lib/rag-core.sh"))->toBeTrue();

    $cfg = File::get("$target/.claude/hooks/config.sh");
    expect($cfg)->toContain('http://localhost:8080')->and($cfg)->toContain('tok123')
        ->and($cfg)->not->toContain('__RAG_URL__');

    $mcp = json_decode(File::get("$target/.mcp.json"), true);
    expect($mcp['mcpServers'])->toHaveKey('rag')->and($mcp['mcpServers'])->not->toHaveKey('martis');

    File::deleteDirectory($target);
});

it('merges into an existing .mcp.json without clobbering', function () {
    $target = tmpTarget();
    File::put("$target/.mcp.json", json_encode(['mcpServers' => ['other' => ['url' => 'x']]]));
    $installer = new ClientInstaller(base_path('stubs/client'));

    $installer->install($target, ['claude'], 'http://localhost:8080', 'tok');

    $mcp = json_decode(File::get("$target/.mcp.json"), true);
    expect($mcp['mcpServers'])->toHaveKeys(['other', 'rag']);

    File::deleteDirectory($target);
});

it('is idempotent on a second run', function () {
    $target = tmpTarget();
    $installer = new ClientInstaller(base_path('stubs/client'));
    $installer->install($target, ['claude'], 'http://localhost:8080', 'tok');
    $first = File::get("$target/.mcp.json");

    $installer->install($target, ['claude'], 'http://localhost:8080', 'tok');
    $second = File::get("$target/.mcp.json");

    expect($second)->toBe($first);
    File::deleteDirectory($target);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Install/ClientInstallerTest.php`
Expected: FAIL — class `ClientInstaller` not found.

- [ ] **Step 3: Implement the service**

```php
<?php
// app/Services/Install/ClientInstaller.php

namespace App\Services\Install;

use Illuminate\Support\Facades\File;

class ClientInstaller
{
    public function __construct(private string $stubsRoot) {}

    /**
     * @param  array<int, string>  $harnesses
     * @return array<int, string>
     */
    public function install(string $target, array $harnesses, string $url, string $token): array
    {
        $written = [];
        foreach ($harnesses as $harness) {
            $method = 'install'.ucfirst($harness);
            $written = array_merge($written, $this->{$method}($target, $url, $token));
        }

        return $written;
    }

    public function substitute(string $contents, string $url, string $token): string
    {
        return str_replace(['__RAG_URL__', '__RAG_TOKEN__'], [$url, $token], $contents);
    }

    /** Copy a stub file with placeholder substitution, preserving exec bit for .sh. */
    private function copyFile(string $from, string $to, string $url, string $token): void
    {
        File::ensureDirectoryExists(dirname($to));
        File::put($to, $this->substitute(File::get($from), $url, $token));
        if (str_ends_with($to, '.sh')) {
            @chmod($to, 0755);
        }
    }

    /** Recursively copy the shared shell core (lib/ + config.sh) into a hooks dir. */
    private function copyShared(string $hooksDir, string $url, string $token): array
    {
        $this->copyFile("{$this->stubsRoot}/hooks/lib/rag-core.sh", "$hooksDir/lib/rag-core.sh", $url, $token);
        $this->copyFile("{$this->stubsRoot}/hooks/config.sh", "$hooksDir/config.sh", $url, $token);

        return ["$hooksDir/lib/rag-core.sh", "$hooksDir/config.sh"];
    }

    public function mergeJsonFile(string $path, array $incoming): void
    {
        $existing = File::exists($path)
            ? (json_decode(File::get($path), true) ?: [])
            : [];
        $merged = $this->deepMerge($existing, $incoming);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    private function deepMerge(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            $a[$k] = (is_array($v) && isset($a[$k]) && is_array($a[$k]))
                ? $this->deepMerge($a[$k], $v)
                : $v;
        }

        return $a;
    }

    public function appendMarkedBlock(string $path, string $block): void
    {
        $current = File::exists($path) ? File::get($path) : '';
        if (str_contains($current, '<!-- rag:begin -->')) {
            return; // already present — idempotent
        }
        $sep = ($current !== '' && ! str_ends_with($current, "\n")) ? "\n\n" : "\n";
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $current.$sep.$block."\n");
    }

    // ---- per-harness installers ----

    private function installClaude(string $t, string $url, string $token): array
    {
        $w = [];
        foreach (['session-start.sh', 'user-prompt.sh', 'stop.sh'] as $s) {
            $this->copyFile("{$this->stubsRoot}/claude/hooks/$s", "$t/.claude/hooks/$s", $url, $token);
            $w[] = ".claude/hooks/$s";
        }
        $w = array_merge($w, $this->copyShared("$t/.claude/hooks", $url, $token));

        $this->copyFile("{$this->stubsRoot}/claude/skills/using-rag/SKILL.md", "$t/.claude/skills/using-rag/SKILL.md", $url, $token);
        $this->mergeJsonFile("$t/.claude/settings.json", json_decode($this->substitute(File::get("{$this->stubsRoot}/claude/settings.json"), $url, $token), true));
        $this->mergeJsonFile("$t/.mcp.json", json_decode($this->substitute(File::get("{$this->stubsRoot}/claude/mcp.json"), $url, $token), true));

        return array_merge($w, ['.claude/settings.json', '.mcp.json', '.claude/skills/using-rag/SKILL.md']);
    }

    private function installCodex(string $t, string $url, string $token): array
    {
        $w = [];
        foreach (['session-start.sh', 'user-prompt.sh', 'stop.sh'] as $s) {
            $this->copyFile("{$this->stubsRoot}/codex/hooks/$s", "$t/.codex/hooks/$s", $url, $token);
            $w[] = ".codex/hooks/$s";
        }
        $w = array_merge($w, $this->copyShared("$t/.codex/hooks", $url, $token));
        $this->mergeJsonFile("$t/.codex/hooks.json", json_decode($this->substitute(File::get("{$this->stubsRoot}/codex/hooks.json"), $url, $token), true));

        // Append the [mcp_servers.rag] TOML snippet if not already present.
        $tomlPath = "$t/.codex/config.toml";
        $snippet = $this->substitute(File::get("{$this->stubsRoot}/codex/config.toml.snippet"), $url, $token);
        $existing = File::exists($tomlPath) ? File::get($tomlPath) : '';
        if (! str_contains($existing, '[mcp_servers.rag]')) {
            File::ensureDirectoryExists(dirname($tomlPath));
            File::put($tomlPath, rtrim($existing)."\n\n".$snippet."\n");
        }

        $this->appendMarkedBlock("$t/AGENTS.md", $this->substitute(File::get("{$this->stubsRoot}/AGENTS.rag.md"), $url, $token));

        return array_merge($w, ['.codex/hooks.json', '.codex/config.toml', 'AGENTS.md']);
    }

    private function installCursor(string $t, string $url, string $token): array
    {
        $w = [];
        foreach (['session-start.sh', 'stop.sh'] as $s) {
            $this->copyFile("{$this->stubsRoot}/cursor/hooks/$s", "$t/.cursor/hooks/$s", $url, $token);
            $w[] = ".cursor/hooks/$s";
        }
        $w = array_merge($w, $this->copyShared("$t/.cursor/hooks", $url, $token));
        $this->mergeJsonFile("$t/.cursor/hooks.json", json_decode($this->substitute(File::get("{$this->stubsRoot}/cursor/hooks.json"), $url, $token), true));
        $this->mergeJsonFile("$t/.cursor/mcp.json", json_decode($this->substitute(File::get("{$this->stubsRoot}/cursor/mcp.json"), $url, $token), true));
        $this->copyFile("{$this->stubsRoot}/cursor/cursorrules", "$t/.cursorrules", $url, $token);

        return array_merge($w, ['.cursor/hooks.json', '.cursor/mcp.json', '.cursorrules']);
    }

    private function installOpencode(string $t, string $url, string $token): array
    {
        $this->copyFile("{$this->stubsRoot}/opencode/plugin/rag.ts", "$t/.opencode/plugin/rag.ts", $url, $token);
        $this->mergeJsonFile("$t/opencode.json", json_decode($this->substitute(File::get("{$this->stubsRoot}/opencode/mcp.snippet.json"), $url, $token), true));
        $this->appendMarkedBlock("$t/AGENTS.md", $this->substitute(File::get("{$this->stubsRoot}/AGENTS.rag.md"), $url, $token));

        return ['.opencode/plugin/rag.ts', 'opencode.json', 'AGENTS.md'];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Install/ClientInstallerTest.php`
Expected: PASS (4 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Install/ClientInstaller.php tests/Feature/Install/ClientInstallerTest.php
git commit -m "feat: add ClientInstaller service (copy + idempotent merge)"
```

---

### Task 11: `rag:install` command (interactive + flags)

**Files:**
- Create: `app/Console/Commands/RagInstallCommand.php`
- Test: `tests/Feature/Install/RagInstallCommandTest.php`

**Interfaces:**
- Consumes: `App\Services\Install\ClientInstaller` (Task 10).
- Produces: artisan command `rag:install {--target=} {--harness=} {--url=} {--token=}`. Missing `--harness` → multi-select prompt; missing `--url` → ask (default `http://localhost:8080`); missing `--token` → ask. Prints a per-harness post-install note (Codex `/hooks` trust).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Install/RagInstallCommandTest.php

use Illuminate\Support\Facades\File;

it('installs the selected harness non-interactively via flags', function () {
    $target = sys_get_temp_dir().'/rag-cmd-'.bin2hex(random_bytes(4));
    File::makeDirectory($target, 0777, true);

    $this->artisan('rag:install', [
        '--target' => $target,
        '--harness' => 'claude',
        '--url' => 'http://localhost:8080',
        '--token' => 'tok',
    ])->assertExitCode(0);

    expect(File::exists("$target/.claude/hooks/stop.sh"))->toBeTrue();
    expect(File::exists("$target/.mcp.json"))->toBeTrue();

    File::deleteDirectory($target);
});

it('fails clearly when the target does not exist', function () {
    $this->artisan('rag:install', [
        '--target' => '/no/such/dir/xyz',
        '--harness' => 'claude',
        '--url' => 'http://localhost:8080',
        '--token' => 'tok',
    ])->assertExitCode(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Install/RagInstallCommandTest.php`
Expected: FAIL — command `rag:install` not defined.

- [ ] **Step 3: Implement the command**

```php
<?php
// app/Console/Commands/RagInstallCommand.php

namespace App\Console\Commands;

use App\Services\Install\ClientInstaller;
use Illuminate\Console\Command;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\text;

class RagInstallCommand extends Command
{
    protected $signature = 'rag:install
        {--target= : Path to the client project (default: cwd)}
        {--harness= : Comma-separated: claude,codex,cursor,opencode}
        {--url= : RAG server base URL}
        {--token= : RAG hook bearer token}';

    protected $description = 'Provision a client project to use the RAG server (hooks + skill + rag MCP).';

    private const HARNESSES = ['claude', 'codex', 'cursor', 'opencode'];

    public function handle(ClientInstaller $installer): int
    {
        $target = (string) ($this->option('target') ?: getcwd());
        if (! is_dir($target)) {
            $this->error("Target directory does not exist: {$target}");

            return self::FAILURE;
        }

        $harnesses = $this->resolveHarnesses();
        if ($harnesses === []) {
            $this->error('No valid harness selected.');

            return self::FAILURE;
        }

        $url = (string) ($this->option('url') ?: text('RAG server URL', default: 'http://localhost:8080'));
        $token = (string) ($this->option('token') ?: text('RAG hook token', required: true));

        $written = $installer->install($target, $harnesses, $url, $token);

        $this->info('Installed RAG integration into '.$target);
        foreach ($written as $rel) {
            $this->line('  + '.$rel);
        }

        if (in_array('codex', $harnesses, true)) {
            $this->warn('Codex: run `/hooks` in the project once to trust the new hooks.');
        }

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function resolveHarnesses(): array
    {
        $opt = (string) $this->option('harness');
        if ($opt !== '') {
            $chosen = array_map('trim', explode(',', $opt));
        } else {
            $chosen = multiselect('Which harness(es)?', self::HARNESSES, required: true);
        }

        return array_values(array_intersect(self::HARNESSES, $chosen));
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Install/RagInstallCommandTest.php`
Expected: PASS (2 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/RagInstallCommand.php tests/Feature/Install/RagInstallCommandTest.php
git commit -m "feat: add rag:install client provisioning command"
```

---

## Phase 4 — This-repo instruction refresh + smoke test

### Task 12: Refresh this repo's own stale instruction files

**Files:**
- Modify: `AGENTS.md` (the RAG section — confirm it mentions hooks/condense flow)
- Create/Modify: `.claude/skills/using-rag/SKILL.md` (this repo, for dogfooding)
- Test: `tests/Feature/Stubs/RepoInstructionsTest.php`

**Interfaces:**
- Produces: this repo carries the `using-rag` skill and an AGENTS.md RAG section aligned with the new hooks flow. (This repo keeps its martis MCP wiring untouched.)

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Stubs/RepoInstructionsTest.php

it('this repo ships the using-rag skill and hooks-aware AGENTS section', function () {
    expect(file_exists(base_path('.claude/skills/using-rag/SKILL.md')))->toBeTrue();

    $agents = file_get_contents(base_path('AGENTS.md'));
    expect($agents)->toContain('rag_search')->and($agents)->toContain('rag_store_knowledge');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Stubs/RepoInstructionsTest.php`
Expected: FAIL — `.claude/skills/using-rag/SKILL.md` missing.

- [ ] **Step 3: Copy the skill into this repo**

Run: `mkdir -p .claude/skills/using-rag && cp stubs/client/claude/skills/using-rag/SKILL.md .claude/skills/using-rag/SKILL.md`

- [ ] **Step 4: Confirm the AGENTS.md RAG section mentions the hooks flow**

The existing `AGENTS.md` "RAG Knowledge Base Integration" section already documents the MCP tools. Add one bullet under its "Triggers" list so the test passes and the flow is documented:

```markdown
- End of session → the Stop hook asks you to condense durable knowledge and call `rag_store_knowledge` (dedup with `rag_search` first); it lands in the pending approval queue.
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Stubs/RepoInstructionsTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add .claude/skills/using-rag/SKILL.md AGENTS.md tests/Feature/Stubs/RepoInstructionsTest.php
git commit -m "docs: add using-rag skill and hooks-aware AGENTS section to this repo"
```

---

### Task 13: End-to-end smoke test script + full suite

**Files:**
- Create: `bin/test-hooks.sh`
- Test: (manual + full suite run)

**Interfaces:**
- Consumes: a running backend (`RAG_HOOK_URL`, `RAG_HOOK_TOKEN`) and the installed Claude adapters.
- Produces: a smoke script that pipes sample stdin JSON into each Claude adapter and prints the raw envelope for eyeballing.

- [ ] **Step 1: Create the smoke script**

```bash
#!/usr/bin/env sh
# Smoke-test the Claude adapters against a running backend.
# Usage: RAG_HOOK_URL=http://localhost:8080 RAG_HOOK_TOKEN=xxx ./bin/test-hooks.sh /path/to/client
set -e
CLIENT="${1:-.}"
H="$CLIENT/.claude/hooks"

echo "== session-start =="
CLAUDE_PROJECT_DIR="$CLIENT" sh "$H/session-start.sh" || true

echo "== user-prompt =="
printf '{"prompt":"how does auth scoping work here"}' | CLAUDE_PROJECT_DIR="$CLIENT" sh "$H/user-prompt.sh" || true

echo "== stop (should emit decision:block) =="
printf '{"stop_hook_active":false}' | sh "$H/stop.sh" || true

echo "== stop (loop guard, should be empty) =="
printf '{"stop_hook_active":true}' | sh "$H/stop.sh" || true
```

- [ ] **Step 2: Make it executable**

Run: `chmod +x bin/test-hooks.sh`

- [ ] **Step 3: Run the full backend + stubs + install suite**

Run: `php artisan test tests/Feature/Hooks tests/Feature/Stubs tests/Feature/Install`
Expected: PASS (all green).

- [ ] **Step 4: Manual smoke (requires backend up)**

Provision a scratch client and run the smoke script:

```bash
php artisan rag:install --target=/tmp/rag-client --harness=claude --url=http://localhost:8080 --token="$RAG_HOOK_TOKEN"
RAG_HOOK_URL=http://localhost:8080 RAG_HOOK_TOKEN="$RAG_HOOK_TOKEN" ./bin/test-hooks.sh /tmp/rag-client
```
Expected: `session-start` exits cleanly (project ensured); `stop (active:false)` prints a `{"decision":"block",...}` JSON; `stop (active:true)` prints nothing.

- [ ] **Step 5: Commit**

```bash
git add bin/test-hooks.sh
git commit -m "test: add hook adapters smoke script"
```

---

## Self-Review Notes

- **Spec coverage:** endpoints (Tasks 1–3); shared core (4); Claude/Codex/Cursor/opencode adapters (5–8); AGENTS template (9); installer service + command (10–11); skill/instruction refresh (9, 12); `RAG_HOOK_INJECT_ON_START` default-off (4, honored in every session-start adapter); rag-only MCP asserted in Tasks 5–8, 10; idempotency (10); Cursor no-per-prompt gap (7, test asserts absence); Codex trust note (11).
- **Type consistency:** `ClientInstaller` method names (`install`, `substitute`, `mergeJsonFile`, `appendMarkedBlock`, `copyShared`) match between Task 10 definition and Task 11 usage. Endpoint paths (`ensure-project`, `digest`, `search`) are identical across `rag-core.sh`, the TS plugin, and the controller routes.
- **Known runtime dependency:** hook scripts use `python3` for JSON escaping/parsing (present on macOS/Linux dev machines). Noted here so execution doesn't treat it as a defect.
```
