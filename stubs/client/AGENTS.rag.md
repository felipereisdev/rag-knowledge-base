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
