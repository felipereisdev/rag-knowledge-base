# RAG Hooks + Multi-Harness Integration — Design

**Date:** 2026-07-09
**Status:** Approved (design), pending implementation plan
**Author:** brainstorming session

---

## 1. Problem & goals

The RAG knowledge base already has a strong storage/retrieval core (hybrid
vector + keyword + graph search, an entity/relation graph, a `pending →
approved` approval workflow, multi-project support, an MCP server, and CLI
commands). Its weakness is **ingestion is active, not passive**: knowledge only
enters the base when the agent *remembers* to call `rag_store_knowledge`. In
practice sessions end and the knowledge is lost.

Inspired by `claude-mem`, we add a **hook layer** that captures passively — but,
unlike `claude-mem`, we keep the curated `pending` approval gate, so passive
capture feeds a review queue instead of polluting the base.

Three lifecycle behaviors, across four harnesses (Claude Code, Codex CLI,
Cursor, opencode):

1. **SessionStart** — ensure the `Project` exists; *optionally* inject a digest
   of approved knowledge into context.
2. **UserPromptSubmit** — auto-search the base with the user's prompt and inject
   the most relevant hits.
3. **Stop / turn-complete** — hand control back to the *current session* and
   instruct it to condense its own work and store it via `rag_store_knowledge`
   (lands in `pending`).

Plus two supporting deliverables:

4. A **skill / instruction layer** teaching each harness how/when to use the RAG.
5. An **installer command** that provisions a *client project* to use the RAG
   server for a chosen harness.

### Key insight (why the Stop hook doesn't run its own LLM)

The current session already holds the full context of the work. Making a second
LLM re-read a transcript to rediscover what happened is more expensive and
lower-quality. So the Stop hook does **not** condense — it returns a
continuation instruction (`decision:block` / `followup_message` / a
`session.prompt` call) that makes the current session condense and call the MCP
tool itself. Guarded against infinite loops per-harness.

---

## 2. Two contexts (critical distinction)

| Context | What it is | Gets martis MCP? | Gets rag MCP + hooks + skill? |
|---|---|---|---|
| **This repo** (`PERSONAL/rag`) | The RAG *server* being developed | ✅ yes (dev tooling) | it's the server itself |
| **A client project** | Any other codebase wanting RAG memory | ❌ **no** | ✅ yes, pointing at the running server |

The client-side artifacts we install **never** wire the martis MCP — only the
`rag` MCP. Martis is the admin engine of this repo, irrelevant to a client.
This repo's own `.mcp.json` / `.cursor/mcp.json` / `opencode.jsonc` (which
currently wire martis) are left as-is; they are *this repo's* dev config.

---

## 3. Architecture

The RAG's established pattern is: business logic in **Services**, thin
**adapters** on top (today: MCP Tools + artisan Commands). We follow it — adding
a thin **HTTP adapter** the hooks consume, plus per-harness hook adapters that
all funnel into one shared core.

```
                    ┌─ Backend HTTP (shared, harness-agnostic) ─┐
                    │  POST /hooks/ensure-project                │
                    │  POST /hooks/digest                        │
                    │  POST /hooks/search                        │
                    └───────────────────▲───────────────────────┘
                                         │ curl (shell) / fetch (TS)
        ┌──────────────┬────────────────┼──────────────────┬───────────────┐
   hooks/lib/rag-core.sh  (curl + config, DRY for shell harnesses)          │
        │              │                │                   │        .opencode/plugin/rag.ts
  .claude/hooks/*  .codex/hooks/*   .cursor/hooks/*                   (fetch directly)
  +settings.json   +hooks.json      +hooks.json
```

- Endpoints return **pre-formatted text ready to inject** (mirroring the MCP
  tools' `Response::text()`), so shell hooks are just `curl + echo` — no `jq`.
- One **shared shell core** (`rag-core.sh`) owns all curl/config logic.
- **Thin per-harness adapters** translate that harness's stdin → core args and
  core output → that harness's output envelope.
- **opencode** is a TS plugin (not shell); it calls the same endpoints via
  `fetch`.

### 3.1 Backend HTTP adapter

New `App\Http\Controllers\HookController`, routes in `routes/hooks.php` under a
`/hooks` prefix, protected by a bearer token (`RAG_HOOK_TOKEN`), intended for
localhost / trusted-network use. All three reuse existing Services.

| Endpoint | Body | Reuses | Returns (text/plain) |
|---|---|---|---|
| `POST /hooks/ensure-project` | `{cwd}` | `ResolvesProjectId::ensureProject` | `project_id` (+ created flag) |
| `POST /hooks/digest` | `{cwd, limit?}` | Eloquent over `KnowledgeEntry` (approved only) | compact index: `title · category · tags` |
| `POST /hooks/search` | `{cwd, query, limit?, min_score?}` | `HybridSearcher` (same config as `RagSearchTool`) | formatted results block |

- `digest` returns **approved-only** entries (pending aren't searchable). Empty
  when the project is new / nothing approved → hook injects nothing.
- `search` reuses the exact `HybridSearcher` construction from `RagSearchTool`.
- Token check middleware; mismatch → 401 → hook no-ops.

### 3.2 Shared shell core — `rag-core.sh`

Sourced by the Claude/Codex/Cursor adapters. Functions:

- `rag_config` — load `RAG_HOOK_URL`, `RAG_HOOK_TOKEN`, `RAG_HOOK_INJECT_ON_START`,
  `RAG_HOOK_SEARCH_MIN_SCORE`, `RAG_HOOK_SEARCH_LIMIT`, `RAG_HOOK_CONDENSE` from
  `<hookdir>/config.sh` with sane defaults.
- `rag_ensure_project <cwd>` → curl `/hooks/ensure-project`.
- `rag_digest <cwd>` → curl `/hooks/digest`.
- `rag_search <cwd> <prompt>` → curl `/hooks/search`.
- `rag_condense_instruction` → emit the static condensation prompt text (below).

Every curl uses `--max-time` (short) and failures are swallowed (`|| true`) so a
down/unreachable server makes the hook a **silent no-op**. Hooks never break a
session (except Stop's intentional one-shot continuation).

### 3.3 The condensation instruction (Stop)

Returned to the current session, verbatim intent:

> Before you finish: judge whether this session produced any durable knowledge
> (a decision, rule, architecture note, non-obvious fix, convention). If **not**,
> stop normally. If **yes**: first call `rag_search` to check it isn't already
> stored (dedup); then condense it into one or more knowledge entries — each with
> a clear title, Markdown content, category, and any salient `entities` /
> `relations` — and call `rag_store_knowledge` (it lands in `pending` for
> review). Then stop.

Loop guard is per-harness (see §4). Disabled when `RAG_HOOK_CONDENSE=false`.

---

## 4. Per-harness capability matrix & adapters

| Trigger | Claude Code | Codex CLI | Cursor | opencode |
|---|---|---|---|---|
| SessionStart inject | ✅ stdout | ✅ `additionalContext` | ✅ `additional_context` | ✅ `experimental.chat.system.transform` |
| Prompt inject | ✅ stdout | ✅ `additionalContext` | ❌ **not supported** | ✅ `chat.message` parts |
| Stop → condense | ✅ `decision:block` + `stop_hook_active` | ✅ same as Claude | ⚠️ `followup_message` + `loop_count`/`loop_limit` | ✅ `session.idle` → `client.session.prompt` |
| Hook config file | `.claude/settings.json` | `.codex/hooks.json` (trust via `/hooks`) | `.cursor/hooks.json` | `.opencode/plugin/rag.ts` (auto-loaded) |
| Mechanism | shell | shell | shell | TS plugin |
| Loop guard | `stop_hook_active` bool | `stop_hook_active` bool | `loop_count` + `loop_limit` (cfg) | session-keyed `Set` flag |

### Documented capability gaps (platform limits, not design choices)

1. **Cursor cannot inject context per-prompt** — `beforeSubmitPrompt` can only
   allow/block, not inject. On Cursor, auto-search is dropped; Cursor gets
   **SessionStart digest + Stop condense** only.
2. **opencode is a TS plugin, not shell** — it does not reuse `rag-core.sh`; it
   reimplements the ~15 lines of `fetch` against the same endpoints.
3. **Codex requires one-time trust** of project hooks via the `/hooks` command;
   the installer prints this instruction.

### Adapter files (client-side, per harness)

- **Claude:** `.claude/hooks/{session-start,user-prompt,stop}.sh` + `hooks/lib/rag-core.sh` + `hooks/config.sh`; wired in `.claude/settings.json`.
- **Codex:** `.codex/hooks/{session-start,user-prompt,stop}.sh` (reuse core) + `.codex/hooks.json`.
- **Cursor:** `.cursor/hooks/{session-start,stop}.sh` (no user-prompt) + `.cursor/hooks.json`.
- **opencode:** `.opencode/plugin/rag.ts` (session.created / chat.message / session.idle).

---

## 5. Skill / instruction layer

Current state:
- ❌ No Claude skill for the RAG (`.claude/` only has `worktrees/`).
- ⚠️ `.cursorrules` is **stale** — references a dead Python `rag/server/main.py`
  and port `8765`.
- ⚠️ `.cursor/mcp.json` and `opencode.jsonc` wire only martis — the **rag MCP is
  not wired** there.
- ✅ `AGENTS.md` has a current RAG section (Codex/Cursor/opencode read it).

Plan — one source of truth, propagated per harness:
- **Create** `.claude/skills/using-rag/SKILL.md` — when to search (search-first),
  when to store, and that capture is now automatic via hooks (just review the
  approval queue).
- **Rewrite** `.cursorrules` to the current MCP/HTTP reality (remove dead Python).
- **Update** the `AGENTS.md` RAG section to mention the hooks + condense flow.
- These instructional artifacts are also what the installer writes into client
  projects (templated).

---

## 6. Installer command — `rag:install`

New `App\Console\Commands\RagInstallCommand`. Provisions a **client project** to
use the RAG server for a chosen harness. No installer exists today.

```bash
php artisan rag:install \
  --target=/path/to/client \
  --harness=claude,codex,cursor,opencode \
  --url=http://localhost:8080 \
  --token=...
```

- **Interactive by default:** missing `--harness` → prompt (multi-select);
  missing `--url`/`--token` → prompt. Assumes the RAG server is already running;
  the installer only *connects the client to it*.
- **Materializes from versioned templates** in this repo (e.g. `stubs/client/`):
  copies the hook scripts + core lib, writes `hooks/config.sh`, writes the skill /
  instruction files, and injects the **rag** MCP wiring in each harness's format:
  - Claude → `.claude/settings.json` (hooks) + `.mcp.json` (rag) + skill file.
  - Codex → `.codex/hooks.json` + `[mcp_servers.rag]` in `.codex/config.toml` + `AGENTS.md` section.
  - Cursor → `.cursor/hooks.json` + `.cursor/mcp.json` (rag) + `.cursorrules`.
  - opencode → `.opencode/plugin/rag.ts` + `mcp.rag` in `opencode.json(c)` + `AGENTS.md` section.
- **Idempotent & non-destructive:** merges into existing JSON/TOML (append hooks
  / mcp entries, never clobber the user's file); re-running is safe; `chmod +x`
  on scripts.
- **Placeholder substitution:** server URL + token baked into `hooks/config.sh`
  and the MCP entries; `project_id` is left to resolve from `cwd` via
  `ResolvesProjectId`.
- **Never writes martis** — rag only.
- Prints post-install notes (e.g. Codex `/hooks` trust step).

---

## 7. Config & security

Env consumed by hooks (from `hooks/config.sh`), with defaults:

| Var | Default | Meaning |
|---|---|---|
| `RAG_HOOK_URL` | `http://localhost:8080` | RAG server base URL |
| `RAG_HOOK_TOKEN` | (required) | bearer token for `/hooks/*` |
| `RAG_HOOK_INJECT_ON_START` | `false` | **opt-in** SessionStart digest injection |
| `RAG_HOOK_SEARCH_MIN_SCORE` | `0.40` | auto-search relevance floor |
| `RAG_HOOK_SEARCH_LIMIT` | `3` | auto-search max hits |
| `RAG_HOOK_CONDENSE` | `true` | enable Stop condensation |

- SessionStart **project creation always runs**; only the **digest injection** is
  gated by `RAG_HOOK_INJECT_ON_START` (default off, per requirement).
- `/hooks/*` endpoints require the bearer token; localhost-scoped.

---

## 8. Error handling

- Every hook curl: short `--max-time`, failures swallowed → **silent no-op** on
  server down / 401 / new project / no approved entries.
- No hook blocks a session except **Stop** (intentional, one-shot, loop-guarded).
- Malformed backend responses → hook injects nothing rather than erroring.

---

## 9. Testing

- **Backend (Pest/PHPUnit feature tests):** `/hooks/ensure-project` creates the
  project; `/hooks/digest` returns approved-only; `/hooks/search` wraps
  `HybridSearcher`; token gating returns 401 on mismatch.
- **Hooks:** `bin/test-hooks.sh` pipes sample per-harness stdin JSON into each
  adapter against a running backend and asserts the output envelope shape.
- **Installer:** test that `rag:install --target=<tmp> --harness=…` writes the
  expected files per harness, merges idempotently (second run is a no-op), and
  never writes martis.

---

## 10. Scope / YAGNI

**In scope:** shared HTTP endpoints; shared shell core + 3 shell adapters + 1 TS
plugin; the condensation continuation flow; skill/instruction layer; `rag:install`.

**Explicitly cut:**
- `PostToolUse`-style raw tool-log capture (destroys curation).
- A separate LLM to condense (the current session does it better).
- Transcript parsing.
- Touching martis wiring in client projects.
- Per-prompt injection on Cursor (platform can't).

**Assumptions:** the RAG server is already running/deployed when a client is
provisioned; the installer only wires the client to it.
