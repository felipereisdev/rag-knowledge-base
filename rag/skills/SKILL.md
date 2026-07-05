# RAG Knowledge Base Plugin

Provides tools to index project files and store knowledge per project, with approval workflow.

## WHEN TO CALL THESE TOOLS (trigger rules)

You MUST call RAG tools automatically when these patterns appear:

### `rag_auto_init` — CALL WHEN:
- A user mentions a project path or file path
- A user asks about a codebase you haven't indexed yet
- You see `@/path/to/file`, `git status`, `ls`, `cd project` in context
- A user starts describing a software project
- You're in a new conversation thread and see project files

### `rag_auto_scan` — CALL WHEN:
- `rag_auto_init` just completed successfully
- A user says "I just created this", "I'm working on this", "let me show you"
- You need to answer a question about the codebase and have no indexed results

### `rag_search` — CALL WHEN:
- A user asks "how does X work?", "where is Y defined?", "what does Z do?"
- A user asks about business logic, architecture, design decisions
- You're about to answer a code question and the project is indexed
- A user asks "what do you know about this project?"

### `rag_store_knowledge` — CALL WHEN:
- A user explains a business rule ("orders over 1000 need approval")
- A user makes a design decision ("we chose Postgres because...")
- You discover a pattern in the code
- A user mentions why something is done a certain way
- You find a notable convention, constraint, or architecture detail
- A user says "remember that..." or "the important thing is..."

### `rag_open_approval_ui` — CALL AFTER:
- Every `rag_auto_scan` or `rag_store_knowledge` call
- So the user can review and approve what's pending

## Workflow examples

**User says:** "I'm working on the Django project at ~/projects/myapp"
→ `rag_auto_init({"root_path": "~/projects/myapp"})` → `rag_auto_scan({"max_files": 200})` → `rag_open_approval_ui({})`

**User asks:** "How does authentication work in this project?"
→ `rag_search({"query": "authentication"})` — if empty, auto-init and scan first

**User says:** "Remember, orders over 1000 need manager approval"
→ `rag_store_knowledge({"title": "Order approval threshold", "content": "...", "category": "business-rule"})` → `rag_open_approval_ui({})`

## Auto-detection

`rag_auto_init` detects project type from indicator files:
- `package.json` → Node.js/Express/Next.js
- `manage.py` → Django
- `go.mod` → Go
- `Cargo.toml` → Rust
- `requirements.txt` → Python

`rag_auto_scan` uses type-specific patterns (skips Django migrations, node_modules, etc.)

## Knowledge categories for rag_store_knowledge

- `business-rule` — business logic rules and policies
- `design-decision` — why something was built a certain way
- `architecture` — system architecture, components, data flow
- `constraint` — technical/business constraints
- `convention` — coding/style conventions
- `insight` — general useful knowledge
