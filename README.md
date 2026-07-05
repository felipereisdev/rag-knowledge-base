# RAG Knowledge Base

A per-project RAG (Retrieval-Augmented Generation) knowledge base for AI coding assistants,
with an **approval workflow** so you control what goes into the knowledge base.

**Supported assistants:**
- [Claude](#claude) (Claude Code, Anthropic)
- [Codex](#codex) (OpenAI)
- [Cursor](#cursor)
- [OpenCode](#opencode)

## Why?

AI coding assistants are powerful, but they forget context between conversations and
don't learn from past discussions. This plugin lets you build a persistent, searchable
knowledge base per project:

- **Business rules** — "orders over €1000 need manager approval"
- **Design decisions** — "we chose Postgres over MongoDB because..."
- **Architecture** — "auth uses JWT with refresh tokens in Redis"
- **Code context** — indexed source files for semantic search
- **Conventions** — "we use camelCase for API fields"

Everything goes through an **approval workflow** — you review what gets indexed before
it becomes searchable.

## Quick start

```bash
# 1. Install the plugin for your assistant (see below)
# 2. Open a new conversation in your project
# 3. Say: "index this project for RAG"
# 4. Approve the chunks in the web UI
# 5. Ask questions: "how does authentication work?"
```

The model will also **proactively index** projects and **store knowledge** during conversations.

---

## Codex

### Installation

```bash
# Clone the repo
git clone https://github.com/felipereisdev/rag-knowledge-base.git ~/rag-knowledge-base

# Install the plugin
codex plugin add rag@personal

# Or manually, after ensuring the marketplace entry exists:
codex plugin add rag@personal
```

If the plugin is not found, add the marketplace entry first:

```bash
cat >> ~/.agents/plugins/marketplace.json << 'EOF'
{
  "name": "personal",
  "interface": { "displayName": "Personal" },
  "plugins": [
    {
      "name": "rag",
      "source": { "source": "local", "path": "./plugins/rag" },
      "policy": { "installation": "AVAILABLE", "authentication": "ON_INSTALL" },
      "category": "Productivity"
    }
  ]
}
EOF
```

Then install:

```bash
mkdir -p ~/plugins
ln -sfn ~/rag-knowledge-base/rag ~/plugins/rag
codex plugin add rag@personal
```

### Setup per project

Run the install script to add RAG instructions to `AGENTS.md`:

```bash
~/rag-knowledge-base/install.sh ~/projects/my-project
```

Or add this block manually to your `AGENTS.md`:

```markdown
## RAG Knowledge Base

This project has a RAG knowledge base. When indexing or storing knowledge,
use the `rag_*` tools. Before answering code questions, call `rag_search`
to find relevant context.
```

### Usage

Open a **new thread** after installation (so tools are loaded):

```
rag_auto_init     → Detect project type and init
rag_auto_scan     → Index files with smart defaults
rag_open_approval_ui  → Review and approve
rag_search        → Query the knowledge base
rag_store_knowledge   → Store business rules/decisions
```

---

## Claude (Claude Code)

### Installation

```bash
# Clone the repo
git clone https://github.com/felipereisdev/rag-knowledge-base.git ~/rag-knowledge-base

# Install dependencies
pip3 install scikit-learn  # for TF-IDF search

# Run the install script for your project
~/rag-knowledge-base/install.sh ~/projects/my-project
```

The install script adds the RAG instructions to `CLAUDE.md` and sets up the server.

### Manual setup

Add to `CLAUDE.md` in your project root:

```markdown
## RAG Knowledge Base

This project has a RAG knowledge base. Before answering questions, search
the knowledge base for relevant context.

Available commands:
- Start the RAG server: `python3 ~/rag-knowledge-base/rag/server/main.py`
- Search: `python3 ~/rag-knowledge-base/search.py --query "what you want" --project <project-id>`
- Index: `python3 ~/rag-knowledge-base/index.py --path . --project <project-id>`
- Store knowledge: `python3 ~/rag-knowledge-base/store.py --title "rule" --content "..." --category business-rule`
```

### How it works with Claude

Claude reads the `CLAUDE.md` instructions and knows to call the RAG scripts. The
knowledge base is shared with Codex/OpenCode/Cursor if they point to the same
`~/.rag/` database.

### Usage in conversation

```
User: "How does the payment flow work?"
Claude: [calls rag search script internally] "Based on the knowledge base..."
```

---

## Cursor

### Installation

```bash
# Clone the repo
git clone https://github.com/felipereisdev/rag-knowledge-base.git ~/rag-knowledge-base

# Install dependencies
pip3 install scikit-learn

# Run the install script
~/rag-knowledge-base/install.sh ~/projects/my-project
```

### Cursor Rules

Add a `.cursorrules` file to your project (or add to existing):

```markdown
You have access to a RAG knowledge base at ~/.rag/knowledge.db.
Before answering code questions, run:
  python3 ~/rag-knowledge-base/rag/scripts/search.py --query "<question>" --project <project-id>

To index files:
  python3 ~/rag-knowledge-base/rag/scripts/index.py --path . --project <project-id>

To store knowledge:
  python3 ~/rag-knowledge-base/rag/scripts/store.py --title "<title>" --content "<content>"

Start the approval UI to review pending items:
  python3 ~/rag-knowledge-base/rag/server/main.py  # starts web UI at http://127.0.0.1:8765
```

### Cursor Agent mode

In Agent mode, Cursor will execute these scripts automatically based on the rules.

---

## OpenCode

### Installation

```bash
# Clone the repo
git clone https://github.com/felipereisdev/rag-knowledge-base.git ~/rag-knowledge-base

# Install dependencies
pip3 install scikit-learn

# Run the install script
~/rag-knowledge-base/install.sh ~/projects/my-project
```

### AGENTS.md setup

The install script adds this to your project's `AGENTS.md`:

```markdown
## RAG Knowledge Base

This project has a RAG knowledge base. Use the tools in ~/rag-knowledge-base/rag/server/
to index, search, and store knowledge.

- Start server: `python3 ~/rag-knowledge-base/rag/server/main.py`
- Search: scripts at ~/rag-knowledge-base/rag/scripts/
- Approval UI: http://127.0.0.1:8765
```

---

## Architecture

```
~/.rag/knowledge.db          ← SQLite database (shared by all assistants)
~/rag-knowledge-base/rag/
├── .codex-plugin/
│   └── plugin.json           ← Codex plugin manifest
├── .mcp.json                 ← MCP server config
├── server/
│   ├── main.py               ← MCP server (JSON-RPC over stdio)
│   ├── db.py                 ← SQLite layer
│   ├── rag_engine.py         ← Chunking + TF-IDF search
│   ├── web_ui.py             ← Approval web UI
│   └── templates/approval.html
├── scripts/                  ← CLI scripts for non-Codex assistants
│   ├── index.py              ← Index files
│   ├── search.py             ← Search knowledge base
│   └── store.py              ← Store knowledge entry
├── skills/SKILL.md           ← Codex skill instructions
└── README.md
```

The database is shared across all assistants — knowledge indexed by Codex is
searchable by Claude, Cursor, or OpenCode.

## CLI scripts (for Claude, Cursor, OpenCode)

### index.py

```bash
python3 ~/rag-knowledge-base/rag/scripts/index.py \
  --path /path/to/project \
  --project my-project \
  --name "My Project" \
  --type python-generic
```

Opens the approval UI after scanning.

### search.py

```bash
python3 ~/rag-knowledge-base/rag/scripts/search.py \
  --query "authentication flow" \
  --project my-project \
  --top-k 5
```

### store.py

```bash
python3 ~/rag-knowledge-base/rag/scripts/store.py \
  --project my-project \
  --title "Order approval rule" \
  --content "Orders over €1000 require manager approval" \
  --category business-rule
```

## Configuration

The database is at `~/.rag/knowledge.db`. All data stays local — nothing is sent
to any external service.

To reset a project's knowledge:

```bash
sqlite3 ~/.rag/knowledge.db "DELETE FROM chunks WHERE project_id = 'my-project';"
sqlite3 ~/.rag/knowledge.db "DELETE FROM documents WHERE project_id = 'my-project';"
```

To completely reset:

```bash
rm -rf ~/.rag/
```

## Development

```bash
# Test the MCP server
cd ~/rag-knowledge-base/rag && python3 server/main.py

# Validate the plugin
python3 path/to/plugin-creator/scripts/validate_plugin.py .
```

## License

MIT
