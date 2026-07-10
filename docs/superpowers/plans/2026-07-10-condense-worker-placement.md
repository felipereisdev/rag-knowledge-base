# Condense worker placement (Martis-driven) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Martis `CondenseSetting.driver` (`claude_sdk`|`api`) decide where the condense queue worker runs — locally on the host for `claude_sdk`, in Docker for `api` — via a single helper script, with no new env var.

**Architecture:** A tiny artisan command prints the current driver; a shell helper reads it and either runs `php artisan queue:work` on the host (sdk, reuses host `claude` auth) or brings up the dockerized `rag-worker` (api). The Docker `worker` service moves behind a compose profile so it no longer auto-starts.

**Tech Stack:** Laravel 12, PHP 8.4, Pest 4, Docker Compose, POSIX sh.

## Global Constraints

- **NUNCA** adicionar `Co-Authored-By: Claude` (nem qualquer co-autoria) em mensagens de commit.
- Toda mensagem de commit termina com `Claude-Session: https://claude.ai/code/session_01VwTUSUv6UXDKht9pDBFVL4`.
- Testes em **Pest** (`PAO_DISABLE=true php artisan test` if plain output is swallowed).
- Driver is configured ONLY in Martis (`CondenseSetting.driver`) — do NOT add an env var for it.
- Work on branch `feat/condense-worker-placement` (already created). One commit per task.
- Shell is POSIX `sh` (match the existing `stubs/client/**/*.sh` / `docker/entrypoint-worker.sh` style).

---

### Task 1: `rag:condense-driver` artisan command

Prints just the current driver value so a shell script can capture it.

**Files:**
- Create: `app/Console/Commands/CondenseDriverCommand.php`
- Test: `tests/Feature/Console/CondenseDriverCommandTest.php`

**Interfaces:**
- Produces: `php artisan rag:condense-driver` → outputs one line: `claude_sdk` or `api` (the value of `CondenseSetting::current()->driver`).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Console/CondenseDriverCommandTest.php

use App\Models\CondenseSetting;

it('prints the api driver from the setting', function () {
    CondenseSetting::current()->update(['driver' => 'api']);

    $this->artisan('rag:condense-driver')
        ->expectsOutput('api')
        ->assertExitCode(0);
});

it('prints the claude_sdk driver from the setting', function () {
    CondenseSetting::current()->update(['driver' => 'claude_sdk']);

    $this->artisan('rag:condense-driver')
        ->expectsOutput('claude_sdk')
        ->assertExitCode(0);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `PAO_DISABLE=true ./vendor/bin/pest tests/Feature/Console/CondenseDriverCommandTest.php`
Expected: FAIL (command not registered).

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Console\Commands;

use App\Models\CondenseSetting;
use Illuminate\Console\Command;

class CondenseDriverCommand extends Command
{
    protected $signature = 'rag:condense-driver';

    protected $description = 'Print the current condense extractor driver (claude_sdk|api); used by bin/condense-worker.sh to place the worker.';

    public function handle(): int
    {
        $this->line(CondenseSetting::current()->driver);

        return self::SUCCESS;
    }
}
```

(Laravel auto-discovers commands in `app/Console/Commands`; no manual registration needed.)

- [ ] **Step 4: Run to verify it passes**

Run: `PAO_DISABLE=true ./vendor/bin/pest tests/Feature/Console/CondenseDriverCommandTest.php`
Expected: PASS (2/2).

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/CondenseDriverCommand.php tests/Feature/Console/CondenseDriverCommandTest.php
git commit -m "feat: add rag:condense-driver command (prints Martis driver)

Claude-Session: https://claude.ai/code/session_01VwTUSUv6UXDKht9pDBFVL4"
```

---

### Task 2: `bin/condense-worker.sh` helper + Docker `worker` profile

The helper reads the driver and starts the worker in the right place. The Docker `worker` service moves behind a `condense` profile so it stops auto-starting.

**Files:**
- Create: `bin/condense-worker.sh`
- Modify: `docker-compose.yml` (add `profiles: ["condense"]` to the `worker` service)
- Test: `tests/Feature/Console/CondenseWorkerScriptTest.php` (presence + content, mirroring the stub-test style)

**Interfaces:**
- Consumes: `php artisan rag:condense-driver` (Task 1).
- Produces: `./bin/condense-worker.sh` — on `claude_sdk` runs `php artisan queue:work` locally (guarding on `claude` in PATH); on `api` runs `docker compose --profile condense up -d worker`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Console/CondenseWorkerScriptTest.php

it('ships an executable condense-worker helper with both placement branches', function () {
    $path = base_path('bin/condense-worker.sh');

    expect(file_exists($path))->toBeTrue();
    expect(is_executable($path))->toBeTrue();

    $body = file_get_contents($path);

    // reads the driver from the Martis setting
    expect($body)->toContain('rag:condense-driver');
    // sdk branch: guards on the claude binary, runs the worker on the host
    expect($body)->toContain('command -v claude');
    expect($body)->toContain('queue:work');
    // api branch: brings up the dockerized worker via the profile
    expect($body)->toContain('docker compose --profile condense up -d worker');
});

it('gates the docker worker behind the condense compose profile', function () {
    $compose = file_get_contents(base_path('docker-compose.yml'));

    // the worker service must be opt-in (not auto-started by `docker compose up`)
    expect($compose)->toContain('profiles: ["condense"]');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `PAO_DISABLE=true ./vendor/bin/pest tests/Feature/Console/CondenseWorkerScriptTest.php`
Expected: FAIL (script missing; profile absent).

- [ ] **Step 3: Create `bin/condense-worker.sh`**

```sh
#!/usr/bin/env sh
# Start the condense queue worker where the Martis driver says it should run:
#   driver=claude_sdk -> locally on this host (reuses the host's authenticated
#                        `claude` CLI; no API key), like claude-mem
#   driver=api        -> in Docker (the rag-worker service, uses an API key)
# The driver is configured in Martis (Condense Settings); this script derives
# the placement from it — there is no separate env var.
set -e

DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$DIR"

DRIVER=$(php artisan rag:condense-driver -n --no-ansi 2>/dev/null | tr -d '[:space:]')

case "$DRIVER" in
  claude_sdk)
    if ! command -v claude >/dev/null 2>&1; then
      echo "driver=claude_sdk but 'claude' is not on PATH." >&2
      echo "Install & authenticate Claude Code on this host, or switch the driver to 'api' in Martis." >&2
      exit 1
    fi
    echo "driver=claude_sdk -> running the queue worker locally (host claude auth)."
    exec php artisan queue:work --queue=default --tries=3 --sleep=3
    ;;
  api)
    echo "driver=api -> starting the dockerized worker."
    exec docker compose --profile condense up -d worker
    ;;
  *)
    echo "Unknown or empty condense driver: '${DRIVER}'." >&2
    echo "Set the driver in Martis (Condense Settings) and ensure the DB is reachable from this host." >&2
    exit 1
    ;;
esac
```

Then make it executable:

```bash
chmod 0755 bin/condense-worker.sh
```

- [ ] **Step 4: Add the compose profile**

In `docker-compose.yml`, under the `worker:` service, add a `profiles` key so it no longer starts on a bare `docker compose up`. Add it right after `container_name: rag-worker`:

```yaml
  worker:
    build:
      context: .
      dockerfile: Dockerfile.app
      target: production
    container_name: rag-worker
    profiles: ["condense"]
    entrypoint: ["/usr/local/bin/entrypoint-worker.sh"]
```

(Leave everything else in the service unchanged.)

- [ ] **Step 5: Run to verify it passes**

Run: `PAO_DISABLE=true ./vendor/bin/pest tests/Feature/Console/CondenseWorkerScriptTest.php`
Expected: PASS (2/2).

- [ ] **Step 6: Commit**

```bash
git add bin/condense-worker.sh docker-compose.yml tests/Feature/Console/CondenseWorkerScriptTest.php
git commit -m "feat: condense-worker helper places worker by Martis driver; gate docker worker behind compose profile

Claude-Session: https://claude.ai/code/session_01VwTUSUv6UXDKht9pDBFVL4"
```

---

### Task 3: README — "Running the condense worker" section

Document the new workflow and the breaking change (worker no longer auto-starts).

**Files:**
- Modify: `README.md`

**Interfaces:** none (docs).

- [ ] **Step 1: Add the section**

Add a subsection to `README.md` (near the existing Docker / worker docs; if none, append before the license/footer). Use this content verbatim:

```markdown
## Running the condense worker

The out-of-band condenser runs as a queue worker. **Where** it runs is derived
from the extractor **driver** you set in Martis → *Condense Settings*:

- `driver = claude_sdk` → runs **locally on your host**, reusing your
  authenticated `claude` CLI (no API key), like claude-mem.
- `driver = api` → runs **in Docker** (the `rag-worker` service) using the
  provider API key from `config/ai.php`.

Start it with the helper — it reads the driver and places the worker for you:

```sh
./bin/condense-worker.sh
```

- **claude_sdk:** requires `claude` on PATH + authenticated, and this host's
  `.env` pointing at the exposed services (`DB_HOST=127.0.0.1`, `DB_PORT=5433`,
  embedder at `http://localhost:8001/v1`).
- **api:** requires the provider key (e.g. `ANTHROPIC_API_KEY`) in the worker's
  environment.

> **Note:** the Docker `worker` service is now behind the `condense` compose
> profile, so a bare `docker compose up` no longer starts it. Use
> `./bin/condense-worker.sh` (api mode) or
> `docker compose --profile condense up -d worker`.
```

- [ ] **Step 2: Commit**

```bash
git add README.md
git commit -m "docs: document condense worker placement helper

Claude-Session: https://claude.ai/code/session_01VwTUSUv6UXDKht9pDBFVL4"
```

---

## Self-review notes
- Spec coverage: command (Task 1), helper + profile (Task 2), docs (Task 3) — all spec §5 items covered.
- The helper reads the driver via the Task 1 command (`rag:condense-driver`) — name matches across tasks.
- No env var for the driver (matches the design decision). The `queue:work` flags mirror `docker/entrypoint-worker.sh` (`--tries=3 --sleep=3`).
