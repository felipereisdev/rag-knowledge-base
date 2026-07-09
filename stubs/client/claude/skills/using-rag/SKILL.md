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
