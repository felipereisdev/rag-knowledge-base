---
name: using-rag
description: Use whenever you work in a project wired to the RAG knowledge base (the `rag_*` MCP tools are available) — search it BEFORE answering anything about this project's business rules, architecture, past decisions, conventions, or "why is it done this way", and store durable decisions/rules you establish. Also use when the user says "remember this", "save this decision", "what did we decide about X", "look up prior context", wants to import docs into the base, or asks how domain concepts relate. When this base exists, don't answer project-knowledge questions from assumption — check it first.
---

# Using the RAG knowledge base

This project is wired to a **RAG knowledge base**: a curated, approval-gated store of the project's durable knowledge — business rules, architecture, decisions, conventions — searchable over MCP with hybrid **vector + keyword + knowledge-graph** retrieval.

Two habits make it pay off, and both are on you:

1. **Search it before you answer** — build on what the project already decided instead of guessing or contradicting it.
2. **Store durable knowledge well** — so the next session (yours or a teammate's) inherits it instead of rediscovering it.

A human approves every entry before it becomes searchable, so you can store freely without polluting the base — but write entries that are worth approving.

## The tools

| Tool | Reach for it to |
|---|---|
| `rag_search` | Find relevant knowledge. **Your default first move** on any project-knowledge question. |
| `rag_store_knowledge` | Save a durable rule / decision / insight (lands in the pending approval queue). |
| `rag_query_graph` | Explore how one entity (a system, concept, rule) connects to others — relationships plain search misses. |
| `rag_status` | Project overview: entry counts, categories, tags. Good for orienting in an unfamiliar project. |
| `rag_import_document` | Import a `.md`/`.txt` file (markdown split by H1/H2) when knowledge is already written in files. |
| `rag_open_approval_ui` | Get the URL where the human reviews pending entries. |
| `rag_list_projects` | List all registered projects. |

The project is pinned by the MCP connection itself — the installer bakes it into the server URL (`/mcp/rag/<project>`), so you rarely pass `project_id`. If a `rag_*` call ever errors with "could not identify the project", the client is wired to the bare `/mcp/rag` URL: pass `project_id` explicitly, or re-run `rag:install`.

## Search before you answer

Before answering anything about this project's rules, architecture, past decisions, conventions, or *why* something is the way it is, call `rag_search` with a natural-language query. This knowledge is authoritative: prefer it over your own assumptions, and if it contradicts what you were about to say, trust the base and reconcile.

- Hooks may have already injected a session-start digest or per-prompt hits. Treat those as leads, not the full answer — search for the specific detail you need.
- Tuning knobs: `min_score` (default `0.30`) — lower it when a search comes back empty; `category` to narrow the search; `expand_graph` (on by default) pulls in graph-connected entries.
- For a **relationship** question — "what depends on the billing service?", "how does X relate to Y?" — use `rag_query_graph` on the entity. The graph surfaces connections vector search won't.

If a search genuinely returns nothing, say so rather than inventing an answer — an empty base is itself a useful fact to report.

## Store durable knowledge

Store a fact when it is **durable and non-obvious** — something a future session would otherwise waste time rediscovering:

- a decision **and its rationale** ("we chose X over Y because …")
- a business rule or a hard constraint
- an architecture choice or convention that isn't obvious from reading the code
- a non-obvious fix or a gotcha that bit someone

Don't store the ephemeral: routine code edits, anything already obvious from the code, transient state, or claims you're not confident are true. The approval queue costs a human's attention — respect it. Quality over volume: one sharp entry beats five vague ones.

**Always `rag_search` first to dedup.** If the fact is already captured, extend or skip it rather than creating a near-duplicate.

### Anatomy of a good entry

`rag_store_knowledge` takes:

- **title** — a specific, searchable headline. Not "Auth notes" but "Owner scoping is enforced in Resource::indexQuery, not in policies".
- **content** — Markdown. State the fact **and the why**, so a future reader can act on it without more context.
- **category** — one of: `business-rule`, `design-decision`, `architecture`, `convention`, `constraint`, `documentation`, `insight`.
- **tags** — a few lowercase topic tags for filtering.
- **entities** — the salient nouns as `{name, type}` (a system, model, endpoint, concept, rule). These become nodes in the knowledge graph.
- **relations** — `{subject, predicate, object}` triples connecting entities, e.g. `{BillingService, "depends on", StripeGateway}`. This is what makes `rag_query_graph` useful later, so spend the effort here — it compounds across sessions.

**Example of a well-formed entry:**

```yaml
title:    "/hooks/* endpoints are open on localhost, token-gated only when configured"
content:  |
          VerifyHookToken allows the request when RAG_HOOK_TOKEN is empty
          (localhost model, matching the unauthenticated /mcp/rag endpoint).
          Setting a token switches it to fail-closed. So hooks work out of the
          box locally; the token is opt-in hardening for networked deploys.
category: architecture
tags:     [hooks, security, auth]
entities: [{name: "VerifyHookToken", type: "middleware"},
           {name: "RAG_HOOK_TOKEN", type: "config"}]
relations: [{subject: "VerifyHookToken", predicate: "reads", object: "RAG_HOOK_TOKEN"}]
```

## Automatic capture via hooks

This project's harness runs lifecycle hooks so capture doesn't depend on anyone remembering:

- **Session start** ensures the project exists and can inject a digest of approved knowledge.
- **Each prompt** may auto-search and inject relevant hits.
- **Session end** asks you to condense the session's durable knowledge and store it.

When the end-of-session prompt arrives, judge honestly whether anything durable actually happened. If it did: `rag_search` to dedup, then store concise, well-formed entries (with entities and relations). If nothing durable happened, just stop — silence is better than noise in the approval queue.
