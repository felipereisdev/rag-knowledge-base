# Knowledge Base RAG Plugin

Provides tools to store and search knowledge entries per project, with an approval workflow.

## WHEN TO CALL THESE TOOLS (trigger rules)

You MUST call RAG tools automatically when these patterns appear:

### `rag_store_knowledge` — CALL WHEN:
- A user explains a business rule ("orders over 1000 need approval")
- A user makes a design decision ("we chose Postgres because...")
- You discover an architecture pattern ("auth uses JWT with refresh tokens")
- A user mentions a constraint ("the API rate limit is 100 req/min")
- A user says "remember that..." or "the important thing is..."
- You find a notable convention, constraint, or architecture detail

### `rag_import_document` — CALL WHEN:
- A user points to a .md or .txt file with documentation or notes
- A user says "import this file" or "add this doc to the knowledge base"
- You find a README, rules file, or notes document worth indexing

### `rag_search` — CALL WHEN:
- A user asks "what do we know about...?" or "is there a rule about...?"
- A user asks about business logic, architecture, or design decisions
- You're about to answer a question and the project has indexed knowledge
- A user asks "what are the business rules?"

### `rag_list_knowledge` — CALL WHEN:
- A user asks "what's in the knowledge base?"
- A user wants to browse entries by category or tags

### `rag_open_approval_ui` — CALL WHEN:
- A user asks to review or approve pending entries
- Do NOT call automatically after storing/importing

### `rag_status` — CALL WHEN:
- A user asks "what's the status of the knowledge base?"
- You want to see how many entries are stored, pending, or indexed

## Workflow examples

**User says:** "Remember, orders over 1000 need manager approval"
→ `rag_store_knowledge({"title": "Order approval threshold", "content": "Orders over 1000 require manager approval", "category": "business-rule", "tags": ["orders", "approval"]})`

**User says:** "Import this file: docs/rules.md"
→ `rag_import_document({"file_path": "docs/rules.md", "category": "business-rule"})`

**User asks:** "What are our authentication rules?"
→ `rag_search({"query": "authentication rules"})` — if empty, suggest storing knowledge first

**User asks:** "What's in the knowledge base?"
→ `rag_list_knowledge({})` or `rag_status({})`

## Knowledge categories

- `business-rule` — business logic rules and policies
- `design-decision` — why something was built a certain way
- `architecture` — system architecture, components, data flow
- `documentation` — imported documentation and notes
- `insight` — general useful knowledge
- `convention` — coding/style conventions
- `constraint` — technical/business constraints

## Tags

Tags are free-form strings used for organization and filtering. Use them to group
related entries by domain (e.g., "auth", "payments", "database", "api").
