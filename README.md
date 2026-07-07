# Knowledge Base RAG

A per-project knowledge base RAG (Retrieval-Augmented Generation) for AI coding
assistants, with an **approval workflow** so you control what goes into the knowledge base.

**Supported assistants:**
- [Codex](#codex) (OpenAI)
- [Claude](#claude) (Claude Code, Anthropic)
- [Cursor](#cursor)
- [OpenCode](#opencode)
- [Docker](#docker)

## Why?

AI coding assistants forget context between conversations and don't learn from past
discussions. This plugin lets you build a persistent, searchable knowledge base:

- **Business rules** — "orders over €1000 need manager approval"
- **Design decisions** — "we chose Postgres over MongoDB because..."
- **Architecture** — "auth uses JWT with refresh tokens in Redis"
- **Documentation** — imported from markdown/text files
- **Conventions** — "we use camelCase for API fields"

Everything goes through an **approval workflow** — you review what gets indexed before
it becomes searchable.

## Quick start

```bash
# 1. Install the plugin for your assistant (see below)
# 2. Open a new conversation in your project
# 3. Say: "remember that orders over 1000 need manager approval"
# 4. Approve the entry in the web UI
# 5. Ask questions: "what are the business rules?"
```

## Configuration

Environment variables (all optional):

| Variable | Default | Meaning |
|---|---|---|
| `RAG_EMBEDDING_MODEL` | `paraphrase-multilingual-mpnet-base-v2` | sentence-transformers model. Changing it triggers an automatic re-index on next server start. |
| `RAG_EMBEDDING_DIM` | `768` | Embedding dimension (must match the model). |
| `RAG_SEARCH_MIN_SCORE` | `0.30` | Minimum cosine similarity for a vector hit to count. Exact keyword (FTS) matches are not gated by this. |
| `RAG_EMBED_URL` | `http://127.0.0.1:8000/api/embed` | Shared embedding endpoint; extra MCP sessions use it instead of loading their own model copy. |
| `RAG_EMBED_REMOTE` | `1` | Set `0` to force in-process embedding. |

### How search works

`rag_search` runs hybrid retrieval: semantic KNN over per-chunk embeddings
(long entries are chunked, so content beyond the model window is still
found) fused with BM25 keyword search via Reciprocal Rank Fusion — exact
identifiers match even when embeddings miss them. Results are scoped to the
project at the index level and optionally expanded through the knowledge
graph.

---

## Docker

Run the admin panel (React SPA + FastAPI API) in two containers.

### Installation

```bash
git clone https://github.com/felipereisdev/rag-knowledge-base.git ~/rag-knowledge-base
cd ~/rag-knowledge-base
docker compose up -d
```

- **Admin panel:** `http://127.0.0.1:8765`
- **API:** `http://127.0.0.1:8000/api`

The SQLite database is persisted via a volume mount at `~/.rag/knowledge.db`.

### Usage

```bash
docker compose up -d      # start in background
docker compose logs -f     # view logs
docker compose down        # stop
```

To use the MCP server from an assistant, configure it to run inside the API container:

```json
{
  "mcpServers": {
    "rag": {
      "command": "docker",
      "args": ["exec", "-i", "rag-api", "python3", "server/main.py"]
    }
  }
}
```

---

## Codex

### Installation

```bash
git clone https://github.com/felipereisdev/rag-knowledge-base.git ~/rag-knowledge-base
codex plugin add rag@personal
```

### Usage

Open a **new thread** after installation:

```
rag_store_knowledge     → Store a knowledge entry (title, content, category, tags)
rag_import_document     → Import a .md or .txt file
rag_search              → Query the knowledge base
rag_list_knowledge      → List entries with filters
rag_remove_knowledge    → Remove an entry
rag_open_approval_ui    → Review and approve pending entries
rag_status              → Show knowledge base stats
rag_list_projects       → List all projects
```

---

## Claude (Claude Code)

### Installation

```bash
git clone https://github.com/felipereisdev/rag-knowledge-base.git ~/rag-knowledge-base
pip3 install scikit-learn  # optional, for better search
~/rag-knowledge-base/install.sh ~/projects/my-project
```

### Usage

```bash
# Store knowledge
python3 ~/rag-knowledge-base/rag/scripts/store.py \
  --project my-project \
  --title "Order approval rule" \
  --content "Orders over 1000 need manager approval" \
  --category business-rule \
  --tags orders approval

# Import a document
python3 ~/rag-knowledge-base/rag/scripts/import.py \
  --file docs/rules.md \
  --project my-project \
  --init

# Search
python3 ~/rag-knowledge-base/rag/scripts/search.py \
  --query "order approval" \
  --project my-project

# Start approval UI
python3 ~/rag-knowledge-base/rag/server/main.py
```

---

## Cursor

Add to `.cursorrules`:

```markdown
You have access to a knowledge base at ~/.rag/knowledge.db.
Before answering questions about business rules or architecture, search:
  python3 ~/rag-knowledge-base/rag/scripts/search.py --query "<question>" --project <project-id>

To store knowledge:
  python3 ~/rag-knowledge-base/rag/scripts/store.py --project <project-id> --title "<title>" --content "<content>" --category <category>

To import a document:
  python3 ~/rag-knowledge-base/rag/scripts/import.py --file <path> --project <project-id> --init

Approval UI: http://127.0.0.1:8765
```

---

## OpenCode

The install script adds instructions to `AGENTS.md`:

```markdown
## Knowledge Base RAG

This project has a knowledge base. Use the tools in ~/rag-knowledge-base/rag/
to store, search, and import knowledge.

- Start server: `python3 ~/rag-knowledge-base/rag/server/main.py`
- Search: `python3 ~/rag-knowledge-base/rag/scripts/search.py`
- Store: `python3 ~/rag-knowledge-base/rag/scripts/store.py`
- Import: `python3 ~/rag-knowledge-base/rag/scripts/import.py`
- Approval UI: http://127.0.0.1:8765
```

---

## Architecture

```
~/.rag/knowledge.db          ← SQLite database (shared by all assistants)
~/rag-knowledge-base/
├── Dockerfile.api            ← FastAPI container (API + MCP server)
├── Dockerfile.web            ← nginx + React build container
├── nginx.conf                ← nginx config (SPA + API proxy)
├── docker-compose.yml        ← Container orchestration
├── rag/
│   ├── requirements.txt      ← Python dependencies
│   ├── .codex-plugin/
│   │   └── plugin.json       ← Codex plugin manifest
│   ├── .mcp.json             ← MCP server config
│   ├── server/
│   │   ├── main.py           ← MCP server (JSON-RPC over stdio)
│   │   ├── api.py            ← FastAPI REST API for admin panel
│   │   ├── db.py             ← SQLite layer (entries, tags, projects)
│   │   ├── search_engine.py  ← TF-IDF search over knowledge entries
│   │   └── doc_import.py     ← Markdown/text import parser
│   ├── web/                   ← React admin panel
│   │   ├── src/
│   │   │   ├── pages/        ← Dashboard, Projects, Entries, Approvals, Search
│   │   │   ├── components/   ← Layout, EntryForm, shadcn/ui components
│   │   │   └── lib/api.ts    ← API client
│   │   └── package.json
│   ├── scripts/              ← CLI scripts for non-Codex assistants
│   │   ├── store.py
│   │   ├── import.py
│   │   └── search.py
│   ├── skills/SKILL.md       ← Codex skill instructions
│   └── README.md
```

## Categories

| Category | Use for |
|---|---|
| `business-rule` | Business logic rules and policies |
| `design-decision` | Why something was built a certain way |
| `architecture` | System architecture, components, data flow |
| `documentation` | Imported documentation and notes |
| `insight` | General useful knowledge |
| `convention` | Coding/style conventions |
| `constraint` | Technical/business constraints |

## Database Management

Database: `~/.rag/knowledge.db`. All data stays local.

Reset a project:
```bash
sqlite3 ~/.rag/knowledge.db "DELETE FROM knowledge_entries WHERE project_id = 'my-project';"
sqlite3 ~/.rag/knowledge.db "DELETE FROM tags WHERE project_id = 'my-project';"
```

Full reset:
```bash
rm -rf ~/.rag/
```

## Development

```bash
# Run tests
cd ~/rag-knowledge-base/rag && python3 -m pytest tests/ -v

# Test the MCP server
cd ~/rag-knowledge-base/rag && python3 server/main.py
```

## License

MIT
