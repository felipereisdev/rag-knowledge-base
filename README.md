# RAG Knowledge Base

A standalone **RAG (Retrieval-Augmented Generation) knowledge base** for AI coding assistants. It runs as a self-contained Docker server that any project, in any language, can connect to via **MCP** (Model Context Protocol) over HTTP — no code integration required in the host project.

Key features:

- **MCP server** (7 tools) accessible over HTTP from any harness (Claude Code, Cursor, Codex, VS Code, Continue, …)
- **Passive capture via hooks** — optional `rag:install` wires Claude Code / Codex / Cursor / opencode to auto-create the project, auto-search each prompt, and condense each session into the approval queue
- **Approval workflow** — the assistant stores knowledge as *pending*; you approve via the admin UI before it's searchable
- **Hybrid search** — vector (pgvector) + full-text (tsvector) + reciprocal rank fusion + knowledge-graph expansion
- **Embeddings configurable** — local sidecar (offline, multilingual, free) by default, or another configured Laravel AI provider that emits 768-dimensional vectors
- **Admin panel + graph explorer** — built on Martis, visualize entities and relations interactively
- **Plug-and-play** — clone, `docker compose up`, connect your harness. No PHP on the host, no manual key generation

### Retrieval scores and evaluation

The `SearchResult` contract carries separate retrieval signals; each consuming surface may choose which ones to display:

- `semantic` is the best approved chunk's cosine similarity for a vector-matched result, and is `null` otherwise.
- `keyword` is PostgreSQL's full-text rank when the result matches FTS, and is `null` otherwise.
- `fusion` is the ordering score. It is raw reciprocal-rank fusion for direct vector/FTS matches; for graph-expanded candidates, it is the parent result's `fusionScore` multiplied by `graphWeight`. It is not a probability or confidence score.

Run the checked-in golden-query evaluation against the `rag` project:

```bash
docker compose exec app php artisan rag:evaluate
```

To enforce quality thresholds in a controlled environment:

```bash
docker compose exec app php artisan rag:evaluate \
  --k=5 \
  --min-recall=0.80 \
  --min-mrr=0.70
```

The dataset lives at `resources/evaluations/rag.json`. Add a query only when an approved entry title is stable and a human has confirmed that the entry is relevant to the query.

---

## How it works

```
┌─────────────────────┐         MCP (HTTP)          ┌──────────────────────────┐
│  Your AI assistant  │  ────  POST /mcp/rag  ────▶  │  RAG Knowledge Base      │
│  (any harness)      │                              │  (Docker: app+web        │
│  any language       │  ◀──  JSON-RPC response ───  │   +postgres+embedder)    │
└─────────────────────┘                              └──────────────────────────┘
        │                                                       │
        │ .mcp.json points to                                   │ pgvector + tsvector
        │ http://localhost:8090/mcp/rag                          │ approval workflow
```

The RAG server is **independent** of your project. Your project only needs one entry in its MCP config pointing at the running server. The host project can be Python, Node, Go, Ruby, another Laravel app — anything.

---

## Requirements

The only hard requirement for running the server:

- **Docker** + **Docker Compose** (to run the four-service default stack)

That's it. No PHP, no Composer, no Python needed on your host — everything runs inside containers.

---

## Install the RAG server (one-time)

```bash
# 1. Clone and enter
git clone <repo-url> rag-kb
cd rag-kb

# 2. Start the default local stack (app, web, postgres, embedder)
docker compose up -d --build
```

On first boot the server automatically:
- creates the `.env` from `.env.example`
- generates the `APP_KEY`
- runs the database migrations

The embedder downloads the embedding model on first start (~1 GB, cached in a volume afterward). Wait ~60 seconds for it to become healthy:

```bash
# 3. Wait for the stack to be healthy, then verify
docker compose ps          # all services should show "(healthy)"
curl -s http://localhost:8090/up   # → real-time app response
```

Open the admin panel: **http://localhost:8090/martis**

A default admin user is created automatically on first boot:

| Field | Value |
|---|---|
| Email | `admin@rag.local` |
| Password | `password` |

> **Change this password** after first login (Profile → Change Password) before exposing the server to a network.

Done. The RAG server is running. Now connect your harness (next section).

---

## Connect your harness (any project)

Add the RAG server to your harness's MCP config. The endpoint is **project-scoped** — the last path segment is your project id, which is how a shared server keeps each project's knowledge isolated (it can't see your filesystem, so it can't infer the project on its own):

```
http://localhost:8090/mcp/rag/<your-project-id>
```

Pick any stable slug for `<your-project-id>` (e.g. the repo name) and use the same id everywhere you wire this project. `rag:install` (below) sets this automatically.

### Claude Code / opencode

In your project (the project you want the assistant to have knowledge of), add to `.mcp.json`:

```json
{
  "mcpServers": {
    "rag": {
      "type": "http",
      "url": "http://localhost:8090/mcp/rag/my-project"
    }
  }
}
```

### Cursor

`Settings → Cursor Settings → Features → MCP → + Add MCP Server`:

```json
{
  "mcpServers": {
    "rag": {
      "url": "http://localhost:8090/mcp/rag/my-project"
    }
  }
}
```

### VS Code (Continue / Copilot extension)

Add to `.continue/config.json` (or the equivalent for your MCP-capable extension):

```json
{
  "mcpServers": {
    "rag": {
      "url": "http://localhost:8090/mcp/rag/my-project",
      "transport": "streamable-http"
    }
  }
}
```

### Codex / other CLI agents

Any tool that speaks MCP over HTTP (Streamable transport) connects with the same URL. See the tool's MCP docs for its config format.

> **One server, many projects.** Run the RAG server once and point any number of projects at it. Each project is identified by the id in its MCP URL (`/mcp/rag/<id>`) — since a shared HTTP server can't see your filesystem, that id is what keeps knowledge isolated per project.

### Verify the connection

After connecting, ask your assistant to check the knowledge base status. The assistant will call the `rag_status` tool and report the project's entry counts. You can also test the endpoint directly:

```bash
curl -s -X POST http://localhost:8090/mcp/rag \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -H 'MCP-Protocol-Version: 2025-11-25' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"test","version":"0.0.0"}}}'
```

---

## Passive capture with hooks (`rag:install`)

Connecting the MCP (above) gives your assistant the `rag_*` tools, but it still relies on the assistant *remembering* to call them. The **hook layer** makes capture passive — it wires your harness's lifecycle so knowledge is retrieved and stored automatically, feeding the same pending-approval queue.

What the hooks do:

- **Session start** — ensure the project exists; optionally inject a digest of approved knowledge (opt-in).
- **Each prompt** — auto-search the base and inject relevant hits.
- **Session end** — ask the current session to condense its durable knowledge and store it via `rag_store_knowledge` (→ pending approval).

### Install into a client project

```bash
# from the RAG repo (needs PHP 8.3 on the host, like the dev tooling)
php artisan rag:install \
  --target=/path/to/your/project \
  --harness=claude,codex,cursor,opencode \
  --url=http://localhost:8090
  # --token=... only if the server has RAG_HOOK_TOKEN set (see below)
```

Omit any flag to be prompted for it. The installer is **idempotent and non-destructive** — it merges into existing config (never clobbers your own hooks) and wires **only** the `rag` MCP. Re-running is safe.

| Harness | Session-start inject | Per-prompt search | End-of-session condense | Wiring |
|---|---|---|---|---|
| Claude Code | ✅ | ✅ | ✅ | `.claude/settings.json` + hooks |
| Codex CLI | ✅ | ✅ | ✅ | `.codex/hooks.json` (run `/hooks` once to trust) |
| Cursor | ✅ | — *(platform can't inject per-prompt)* | ✅ | `.cursor/hooks.json` |
| opencode | ✅ | ✅ | ✅ | `.opencode/plugin/rag.ts` |

> Hooks require **`python3`** on the client machine (JSON handling) and fail safe: if the server is unreachable they no-op silently and never break your session.

### Enable the hook endpoints on the server

The hooks call `/hooks/*` routes on the server. If your running `rag-app` image predates this feature, rebuild so the routes exist:

```bash
docker compose up -d --build app web
```

By default these routes are **open on localhost** — the same model as the `/mcp/rag` endpoint, so no token is needed and you can leave `--token` blank in `rag:install`. If you expose the server on a network, lock them down with a token:

```env
# docker-compose.yml, under the `app` service `environment` (optional hardening)
RAG_HOOK_TOKEN=<a-strong-secret>
```

When a token is set, pass the same value as `--token` to `rag:install`. Client-side tuning knobs (in the installed `hooks/config.sh`):

| Variable | Default | Meaning |
|---|---|---|
| `RAG_HOOK_INJECT_ON_START` | `false` | Inject the approved-knowledge digest at session start (opt-in) |
| `RAG_HOOK_SEARCH_MIN_SCORE` | `0.40` | Auto-search relevance floor |
| `RAG_HOOK_SEARCH_LIMIT` | `3` | Max auto-search hits |
| `RAG_HOOK_CONDENSE` | `true` | End-of-session condensation nudge |

> The token is written into the client's `hooks/config.sh` in plaintext — add it to that project's `.gitignore` if the repo is shared.

---

## MCP tools

| Tool | Purpose |
|---|---|
| `rag_status` | Project status (entry counts, tags, categories) plus importance-classifier health (mode, threshold, entries in flight, shadow verdicts, `classification` queue). Auto-creates the project from the working directory. |
| `rag_store_knowledge` | Store a **pending** entry with tags/entities/relations. Requires approval before it's searchable. |
| `rag_search` | Hybrid search: vector + full-text + RRF + knowledge-graph expansion. |
| `rag_query_graph` | Explore entity relationships in the knowledge graph. |
| `rag_import_document` | Import a `.md`/`.txt` file (split by H1/H2 into entries). |
| `rag_open_approval_ui` | Get the URL of the approval panel. |
| `rag_list_projects` | List all projects with stats. |

### Artisan commands (CLI equivalents)

If you're on the host with the repo, you can also drive the server from the CLI:

```bash
docker compose exec app php artisan rag:store "Title" --content="..." --category=business-rule --tags=a,b
docker compose exec app php artisan rag:import path/to/file.md --project=my-project
docker compose exec app php artisan rag:search "query" --project=my-project --limit=5
docker compose exec app php artisan rag:reindex --project=my-project
docker compose exec app php artisan rag:importance-report --project=my-project
```

---

## The approval workflow

1. Your assistant stores knowledge via `rag_store_knowledge` → entry is created with status **pending** (not yet searchable).
2. You review pending entries at **http://localhost:8090/martis/resources/knowledge-entries** and approve or reject them.
3. The always-on `indexer` worker embeds pending entries on the dedicated
   `indexing` queue. Approval makes those pre-indexed entries searchable without
   waiting for the optional session-condensation worker.

This keeps the assistant from polluting the knowledge base with unverified claims — you stay in control of what's searchable.

---

## The importance classifier

Captured knowledge is judged before it reaches your review queue, so the queue stays
worth reading. The classifier is **hybrid**: versioned deterministic rules plus a
semantic judge (a Claude model), combined into a 0–100 score and compared with a
threshold.

It has three modes, set in **Martis → Importance Classifier**
(<http://localhost:8090/martis/resources/importance-classifier-settings>):

| Mode | Behaviour |
|---|---|
| `off` | Capture everything; never judge. Entries go straight to **pending**. |
| `shadow` | Judge every entry and record the verdict, but **never reject and never approve**. Entries still go to **pending**. This is the default. |
| `enforce` | Judge; reject entries scored below the threshold, and auto-approve the ones that clear the auto-approve threshold *and* carry a clean deterministic signal. |

**Fail open.** A technical failure (no `claude` on the worker host, a timeout, an
unparseable answer) never rejects an entry: it is released to **pending** with a
`classification_error` in its metadata. Only a computed `not_important` verdict under
`enforce` rejects anything. A failure never auto-approves an entry either — there is no
score to approve on.

### High-confidence auto-approval

`enforce` has a fourth transition: **classifying → approved**, with no human in the loop.
It fires only when both halves of `AutoApprovalPolicy` hold:

1. `final_score >= auto_approve_threshold`, and
2. at least one deterministic rule scored the candidate **up**, and **no rule scored it
   down** — no penalty, no veto.

The second half is not redundant. An approved entry is retrievable by search, so it is
served to agents as trusted project knowledge that **nobody read**. Candidate text is
untrusted, and the semantic score comes from the very model a prompt injection targets —
so a high score alone can never be enough. The deterministic rules are regex, not
inference: they are the one part of the decision a sentence cannot argue its way past.

**`auto_approve_threshold`** is set in **Martis → Importance Classifier**, defaults to
`90`, and must be **≥ the rejection threshold** (a value below it would approve entries
the classifier is simultaneously rejecting). Leaving it **empty disables auto-approval
entirely** while rejection keeps working — that is the way to back out of auto-approval
alone, without giving up the rest of the classifier.

**`shadow` never approves.** It computes eligibility and records it as
`metadata.importance.would_approve`, exactly as it records `would_reject`, and then does
nothing about either. Only `enforce` acts, and it sets
`metadata.importance.auto_approved` on the entries it approved by itself.

**Auto-approval is reversible.** Rejecting an auto-approved entry from the
knowledge-entries panel purges it from the index immediately — the chunks and embeddings
are deleted and search stops returning it. The **content survives**: the row, the text
and the assessment that approved it are all intact, so you can read what the classifier
thought and put the entry back.

### 1. Setup: the worker runs on a trusted host, not in Docker

The classifier judges by shelling out to the host's authenticated `claude` CLI (no API
key), exactly like the condense worker in `claude_sdk` mode. **The production Docker
image does not provide Claude.** The classification worker therefore runs on a trusted
host where Claude Code is installed and authenticated, with the host `.env` pointed at
the exposed services (`DB_HOST=127.0.0.1`, `DB_PORT=5433`).

```sh
./bin/classification-worker.sh
```

The helper resolves the repo root from its own location, refuses to start when `claude`
is missing, and runs exactly:

```bash
php artisan queue:work classification --queue=classification --tries=3 --timeout=120
```

> **The leading `classification` is the queue *connection*, not a typo.** The job runs
> on a dedicated connection whose `retry_after` (150s) is sized above the job's own
> `$timeout` (120s). Start the worker without that argument and it reserves the same
> rows under the default connection's 90s `retry_after` — *below* the job timeout — so
> the queue re-delivers a classification that is still in flight, a second worker burns
> the attempt counter, and the real verdict is thrown away. The ordering is derived, one
> value from the next, and must hold:
>
> **Claude process timeout (`RAG_IMPORTANCE_TIMEOUT`, 90s) < job `$timeout` (120s) < `classification` connection `retry_after` (150s).**

**Supervision.** Keep the worker running under your host's supervisor (launchd, systemd,
`supervisord`, or a `tmux` session). While it is down, entries captured in `shadow` or
`enforce` sit in **classifying** — invisible to search and to the approval queue. They
are picked up when the worker comes back; the ones that were abandoned mid-flight show
up as **stale** in `rag_status` and in the calibration report. If you must stop
classifying for a while, set the mode to `off` (see rollback below) rather than leaving
entries stranded.

That backstop only holds while the job still exists. A job whose own recovery also threw
lands in `failed_jobs` instead, and nothing else will ever move that entry out of
**classifying** — the admin surface deliberately refuses every other way out. Recover it
with `php artisan queue:retry` (all, or the specific id): `handle()` is fully idempotent,
so a re-run re-classifies the entry from scratch and drives it to `pending`, `approved`,
or `rejected`. Check `failed_jobs` whenever `rag_status` reports stale entries that never
drain.

### 2. Roll out in shadow

Start in `shadow` and leave it there until the numbers justify enforcing. Every entry is
scored and carries its verdict in `metadata.importance`, but nothing is rejected, so a
bad rule or a bad threshold costs you nothing.

Watch it through MCP — `rag_status` reports the classifier next to the rest of the
project's health:

```
  Importance classifier: mode shadow, threshold 70
    Model: claude-haiku-4-5-20251001 | Prompt: v1 | Rules: v6
    Classifying: 3; stale over 15 min: 0
    Assessments: 128 succeeded, 2 failed
    Shadow verdicts: 84 would keep, 44 would reject
    Classification queue (global): 3 pending, 0 failed
```

The same numbers are returned as a nested `importance_classifier` object in the tool's
structured content. Read them as:

- **Classifying / stale** — stale entries mean the worker is not draining the queue.
- **Assessments failed** — a failing model call; the entries still reached you (fail open).
- **Shadow verdicts** — what `enforce` *would* have done.
- **Classification queue** — pending and failed jobs on the `classification` queue.

### 3. Calibrate

```bash
php artisan rag:importance-report --project=my-project
# --min-sample=50   minimum classified entries a human has since reviewed
```

The report prints the score distribution of the shadow sample, the projected queue
reduction, the entries the classifier and the human disagreed about, and an explicit
readiness verdict. Readiness holds **only when all seven rollout gates hold**:

| Gate | Requirement |
|---|---|
| Reviewed sample | ≥ 50 classified entries a human has since approved or rejected (`--min-sample`) |
| Must-keep corpus | 0 false rejects when the current rules re-run the reviewed must-keep corpus |
| False rejects | ≤ 5% of human-**approved** entries marked `would_reject` |
| Queue reduction | ≥ 30% of classified entries would not have reached the review queue |
| Stale classifications | 0 entries stranded in `classifying` |
| **False auto-approvals** | 0 human-**rejected** entries marked `would_approve`, over **≥ 10 rejected entries that were classified with auto-approval in force** |
| **Must-reject corpus** | 0 fixtures of the reviewed must-reject corpus that satisfy the **deterministic half** of eligibility |

The last two gates exist because `enforce` now turns on rejection *and* auto-approval
together. They are the two questions rejection alone never had to answer:

- **False auto-approvals** asks the humans. Of everything a human threw away, how much
  would the classifier have published on its own? Zero, or you are not ready. The **floor
  of ten rejected entries** is half the gate, not a formality: a project that has rejected
  nothing scores a clean `0 / 0` and would otherwise certify auto-approval **on no
  evidence at all**. Ten rejections is the smallest sample in which a false approval had a
  fair chance to show itself. (Rejections recorded before auto-approval existed carry no
  `would_approve` and are not counted — they were never evaluated for this risk.)
- **Must-reject corpus** asks nothing of the model, deliberately. It re-runs the reviewed
  noise fixtures through the deterministic rules alone: not one of them may show a
  positive signal with no penalty. This is the half of the injection defence that a
  captured model cannot influence.

Both gates are skipped when `auto_approve_threshold` is empty — there is nothing to
certify. The command exits `0` when every gate passes and `1` otherwise, so it can gate a
script. **It never changes the mode** — enabling `enforce` is a deliberate human act.

### 4. Enable `enforce` (by hand)

The order matters, because `enforce` switches on both behaviours at once:

1. **Validate rejection in `shadow`.** Leave it there until the sample is real: entries
   scored, verdicts recorded, humans still reviewing everything.
2. **Confirm all seven gates.** `rag:importance-report` must say READY — including the
   two auto-approval gates, which need at least ten human-rejected entries before they
   can be validated at all.
3. **Switch to `enforce`.** Open **Martis → Importance Classifier**, set **Mode** to
   `enforce`, and save. Adjust **Threshold** and **Auto-approve threshold** there too —
   the score distribution in the report tells you whether either sits in a valley or cuts
   through a cluster. This turns on rejection AND auto-approval together, which is safe
   only because READY now covers both.
4. **Review what was auto-approved.** Filter the knowledge-entries panel by
   **Auto-approved** and spot-check what the classifier let into search without you. This
   is the only surface on which those entries are ever seen by a human.

Every decision keeps its full audit trail (score, verdict, reasons, triggered rules,
model, prompt and rules version) on the entry, whichever way it went.

### 5. Rollback

Rolling back is a settings change, not a deployment:

- **auto-approval only**: clear **Auto-approve threshold** in Martis. Nothing is approved
  without a human any more; rejection keeps working exactly as it did.
- **`enforce` → `shadow`**: stop rejecting *and* stop approving immediately; keep
  collecting both verdicts.
- **`shadow` → `off`**: stop judging altogether. Entries already in `classifying` are
  released to **pending** without a verdict by the worker, so keep the worker running
  briefly after switching to `off` to drain them.

Entries already rejected stay rejected — reinstate them from the knowledge-entries panel.
Entries already auto-approved stay approved and searchable; reject them from the same
panel to purge them from the index (the content is kept).

---

## Embeddings

The server supports two embedding backends, configured via environment variables:

### Default: local sidecar (offline, free, multilingual)

The `embedder` service runs a FastAPI app with `paraphrase-multilingual-mpnet-base-v2` (768-dim). No API key, no internet after the initial model download. Good for PT/EN. This is the out-of-the-box default.

```env
RAG_EMBEDDING_PROVIDER=local-embedder
RAG_EMBED_URL=http://embedder:8000/v1   # inside Docker
RAG_EMBED_KEY=rag-local
RAG_EMBEDDING_MODEL=paraphrase-multilingual-mpnet-base-v2
RAG_EMBEDDING_DIM=768
```

### Alternative: configured embedding provider

You may configure another Laravel AI embedding provider instead of the sidecar. Its selected model must emit exactly 768-dimensional vectors. Put the embedding settings and any credential/configuration variables expected by the provider's existing `config/ai.php` entry in the host `.env`:

```env
RAG_EMBEDDING_PROVIDER=<configured-provider>
RAG_EMBEDDING_MODEL=<a-model-configured-to-return-768-dimension-vectors>
RAG_EMBEDDING_DIM=768
```

Start the external-provider stack with only the base Compose file. Supplying `-f` prevents Compose from auto-loading `docker-compose.override.yml`, so the local sidecar is neither created nor required:

```bash
docker compose -f docker-compose.yml up -d --build

# Include the optional queue worker when needed
docker compose -f docker-compose.yml --profile condense up -d --build
```

The base file loads `.env` into `app`, `indexer`, `worker`, and `app-dev` when the file exists, allowing the selected provider's standard Laravel AI environment variables to flow through without Compose-specific credential entries.

> Embedding persistence currently supports 768 dimensions only. The application rejects other dimensions at boot until variable-dimension storage is implemented. A provider or model identity change removes stored chunk embeddings; run `php artisan rag:reindex` to regenerate them.

---

## Configuration

The embedding identity variables are interpolated from the host environment or `.env`, with defaults matching `config/rag.php`. Explicit Compose environment values override values loaded through `env_file`. The relevant variables are:

| Variable | Default | Meaning |
|---|---|---|
| `DB_HOST` | `postgres` | Postgres host (`postgres` inside Docker) |
| `DB_PORT` | `5432` | Postgres port |
| `DB_DATABASE` | `rag` | Database name |
| `DB_USERNAME` / `DB_PASSWORD` | `rag` / `secret` | Database credentials |
| `RAG_EMBEDDING_PROVIDER` | `local-embedder` | Laravel AI embedding provider |
| `RAG_EMBED_URL` | `http://embedder:8000/v1` in local mode | Local sidecar endpoint; not set by the external-safe base file |
| `RAG_EMBEDDING_MODEL` | `paraphrase-multilingual-mpnet-base-v2` | Embedding model name |
| `RAG_EMBEDDING_DIM` | `768` | Embedding dimension; persistence currently requires 768 |
| `RAG_SEARCH_MIN_SCORE` | `0.30` | Minimum score cutoff for search results |
| `RAG_SEARCH_LIMIT` | `10` | Default result limit |
| `RAG_IMPORTANCE_MODEL` | `claude-haiku-4-5-20251001` | Model the importance judge calls through the host's `claude` CLI |
| `RAG_IMPORTANCE_TIMEOUT` | `90` | Claude process timeout (s). The job's `$timeout` (+30s) and the `classification` connection's `retry_after` (+60s) are derived from it — see [the importance classifier](#the-importance-classifier) |
| `RAG_IMPORTANCE_STALE_AFTER_MINUTES` | `15` | After this long in `classifying`, an entry is reported stale |
| `RAG_IMPORTANCE_QUEUE` / `RAG_IMPORTANCE_QUEUE_CONNECTION` | `classification` | Queue name and dedicated connection the classification worker drains |
| `RAG_IMPORTANCE_MAX_REASON_COUNT` | `5` | Max reasons accepted from the judge's response (extra ones are a contract violation) |
| `RAG_IMPORTANCE_MAX_REASON_LENGTH` | `280` | Max characters per reason |
| `MARTIS_AUTH_MIDDLEWARE` | (empty) | Auth middleware for admin routes (empty = no auth — **localhost only**) |

> **Security.** By default the server has no authentication — it is intended for **localhost** use. If you expose it on a network, set `MARTIS_AUTH_MIDDLEWARE` and put the MCP endpoint behind a reverse proxy with auth.

---

## Docker services

| Service | Image | Port | Purpose |
|---|---|---|---|
| `app` | `Dockerfile.app` (PHP-FPM 8.3) | — | Laravel application |
| `web` | `nginx:alpine` | `8090:80` | nginx reverse proxy |
| `indexer` | `Dockerfile.app` | — | Always-on worker for the dedicated `indexing` queue |
| `worker` | `Dockerfile.app` | — | Optional `condense` profile worker for session condensation |
| `postgres` | `pgvector/pgvector:pg16` | `5433:5432` | Postgres + pgvector |
| `embedder` | `services/embedder/` (FastAPI) | `8001:8000` | Local-default sidecar from `docker-compose.override.yml`; absent in external mode |

### Common commands

```bash
# Start / stop
docker compose up -d --build
docker compose down
docker compose down -v          # fresh start (wipes data)

# External provider (reads provider settings/credentials from host .env)
docker compose -f docker-compose.yml up -d --build
docker compose -f docker-compose.yml down

# Inspect
docker compose ps
docker compose logs -f app
docker compose logs -f indexer
docker compose --profile condense logs -f worker

# Run migrations manually
docker compose exec app php artisan migrate

# Tests / static analysis / formatting (uses the dev profile)
docker compose --profile dev up -d --build app-dev
docker compose --profile dev exec app-dev vendor/bin/pest
docker compose --profile dev exec app-dev vendor/bin/phpstan analyse --memory-limit=2G
docker compose --profile dev exec app-dev vendor/bin/pint --test
```

> **Maintenance window:** migrations that rebuild full-text search vectors, and project language changes that rebuild a project's full-text vectors, run synchronously. Schedule these operations during a maintenance window for large knowledge bases.

---

## Running the condense worker

The out-of-band condenser runs as a queue worker. **Where** it runs is derived
from the extractor **driver** you set in Martis → *Condense Settings*:

The default `indexer` service is separate from session condensation and must
remain running so entries continue to be embedded on the `indexing` queue.

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
- **api:** requires the selected provider's standard Laravel AI credential
  variables in the worker's environment (the Compose `.env` pass-through handles these).

> **Note:** the Docker `worker` service is now behind the `condense` compose
> profile, so a bare `docker compose up` no longer starts it. Use
> `./bin/condense-worker.sh` (api mode) or
> `docker compose --profile condense up -d worker`.

---

## Graph explorer

Open **http://localhost:8090/martis/graph** in a browser to visualize entities and their relations as an interactive network graph (powered by vis-network). Filter by project to focus on one knowledge base at a time.

---

## CI/CD

GitHub Actions runs on every push and PR:

| Job | When | What |
|---|---|---|
| `test` | All pushes + PRs | `docker compose up` + Pest (full integration) |
| `lint` | All pushes + PRs | PHPStan level 6 + Pint check |
| `build-and-push` | Push to `main` only | Build `Dockerfile.app` + push to `ghcr.io` |

The Docker image is published to `ghcr.io/<owner>/rag-app:latest` on every merge to main.

---

## Development

```bash
# Run tests on the host (requires PHP 8.3, Composer, and Postgres+pgvector)
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
./vendor/bin/pest

# Static analysis / formatting
./vendor/bin/phpstan analyse --memory-limit=2G
./vendor/bin/pint
```

---

## Project structure

```
app/
├── Mcp/
│   ├── Servers/RagServer.php      # MCP server registration (7 tools)
│   └── Tools/                     # The 7 MCP tool classes
├── Martis/
│   ├── Resources/                 # Admin CRUD definitions
│   └── Dashboards/                # Custom dashboards
├── Models/                        # Project, KnowledgeEntry, Tag, Entity, Relation, ...
├── Services/
│   ├── Search/                    # Hybrid search engine (vector + FTS + RRF + KAG)
│   ├── Graph/                     # Knowledge graph explorer
│   ├── Importing/                 # Document importer (.md/.txt splitting)
│   ├── Indexing/                  # Embedding + indexing pipeline
│   ├── Importance/                # Hybrid importance classifier (rules + judge)
│   └── Chunking/                  # Text chunking strategies
└── Console/Commands/             # rag:store, rag:import, rag:search, rag:reindex, rag:importance-report
bin/                               # Host-side workers: condense-worker.sh, classification-worker.sh
routes/
└── ai.php                         # Registers the RAG MCP server (local + HTTP)
services/
└── embedder/                      # FastAPI embedding sidecar (Python)
docker/                            # nginx, php-fpm, entrypoints
```
