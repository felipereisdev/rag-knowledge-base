# RAG Knowledge Base

A per-project knowledge base RAG (Retrieval-Augmented Generation) for AI coding assistants, with an **approval workflow** so you control what goes into the knowledge base.

**Phase 4 (current):** MCP server (7 tools via `laravel/mcp`), 3 Artisan commands (`rag:store`, `rag:import`, `rag:search`), a graph explorer page at `/martis/graph`, full Docker stack, and a GitHub Actions CI/CD pipeline.

## Requirements

- PHP 8.3+
- Composer 2.x
- Docker (for the full stack, or just Postgres+pgvector if running on host)

## Quick start

```bash
# 1. Clone and enter the project
git clone <repo-url> rag
cd rag

# 2. Copy environment file
cp .env.example .env

# 3. Start all services (app, web, worker, postgres, embedder)
docker compose up -d --build

# 4. Run migrations
docker compose exec app php artisan migrate

# 5. Open the admin panel
open http://localhost:8080/martis
```

### Running without Docker

If you prefer to run the app on your host:

```bash
# 1. Start Postgres+pgvector only
docker compose up -d postgres

# 2. Install dependencies
composer install

# 3. Run migrations and seed
php artisan migrate
php artisan db:seed

# 4. Start the dev server
php artisan serve

# 5. Open the admin panel
open http://localhost:8000/martis
```

## Docker

The `docker-compose.yml` orchestrates 5 services:

| Service | Image | Port | Purpose |
|---|---|---|---|
| `app` | `Dockerfile.app` (PHP-FPM 8.3) | — | Laravel application |
| `web` | `nginx:alpine` | `8080:80` | nginx reverse proxy |
| `worker` | `Dockerfile.app` | — | Queue worker (`queue:work`) |
| `postgres` | `pgvector/pgvector:pg16` | `5433:5432` | Postgres + pgvector |
| `embedder` | `services/embedder/` (FastAPI) | `8001:8000` | Embedding sidecar |

### Common commands

```bash
# Start all services
docker compose up -d --build

# Run migrations
docker compose exec app php artisan migrate

# Run tests (uses the dev profile with test dependencies)
docker compose --profile dev up -d --build app-dev
docker compose --profile dev exec app-dev vendor/bin/pest

# Run static analysis
docker compose --profile dev exec app-dev vendor/bin/phpstan analyse --level=6 --memory-limit=2G

# Check formatting
docker compose --profile dev exec app-dev vendor/bin/pint --test

# View logs
docker compose logs -f app
docker compose logs -f worker

# Stop everything
docker compose down

# Stop and remove volumes (fresh start)
docker compose down -v
```

## CI/CD

GitHub Actions runs on every push and PR:

| Job | When | What |
|---|---|---|
| `test` | All pushes + PRs | `docker compose up` + Pest (full integration) |
| `lint` | All pushes + PRs | PHPStan level 6 + Pint check |
| `build-and-push` | Push to `main` only | Build `Dockerfile.app` + push to `ghcr.io` |

The Docker image is published to `ghcr.io/<owner>/rag-app:latest` on every merge to main.

## MCP Integration

This project exposes a Model Context Protocol (MCP) server so that AI assistants
(Claude Code, Cursor, Codex) can store and search knowledge programmatically.

The `.mcp.json` at the repo root registers the `rag` server:

```json
{
  "mcpServers": {
    "rag": {
      "command": "php",
      "args": ["artisan", "mcp:start", "rag"],
      "cwd": "."
    }
  }
}
```

### Available MCP tools

- `rag_status` — project status (counts, language, tags)
- `rag_store_knowledge` — store a pending entry with tags/entities/relations
- `rag_search` — hybrid vector + FTS + KAG search
- `rag_query_graph` — explore entity relationships
- `rag_import_document` — import a .md/.txt file (split by H1/H2)
- `rag_open_approval_ui` — get the approval URL
- `rag_list_projects` — list all projects with stats

### Artisan commands (CLI equivalents)

```bash
php artisan rag:store "Title" --content="..." --category=business-rule --tags=a,b
php artisan rag:import path/to/file.md --project=my-project
php artisan rag:search "query" --project=my-project --limit=5
php artisan rag:reindex --project=my-project
```

### Graph explorer

Open `http://localhost:8080/martis/graph` in a browser to visualize entities and
relations as an interactive network graph (powered by vis-network). When running
on host with `php artisan serve`, use port `8000` instead.

## Configuration

Environment variables (in `.env`):

| Variable | Default | Meaning |
|---|---|---|
| `DB_CONNECTION` | `pgsql` | Database driver |
| `DB_HOST` | `127.0.0.1` | Postgres host |
| `DB_PORT` | `5433` | Postgres port (host side) |
| `DB_DATABASE` | `rag` | Database name |
| `DB_USERNAME` | `rag` | Database user |
| `DB_PASSWORD` | `secret` | Database password |
| `RAG_EMBEDDING_MODEL` | `paraphrase-multilingual-mpnet-base-v2` | Embedding model |
| `RAG_EMBEDDING_DIM` | `768` | Embedding dimension |
| `MARTIS_AUTH_MIDDLEWARE` | (empty) | Auth middleware for Martis routes (empty = no auth, local only) |

## Development

```bash
# Run tests
./vendor/bin/pest

# Static analysis
./vendor/bin/phpstan analyse --memory-limit=2G

# Format
./vendor/bin/pint
```

## Project structure

- `app/Models/` — Eloquent models (Project, KnowledgeEntry, Tag, Entity, Relation, ProjectPath, ChunkEmbedding, etc.)
- `app/Martis/Resources/` — Martis resources (CRUD UI definitions)
- `app/Martis/Dashboards/` — Martis custom dashboards
- `app/Mcp/Servers/` — MCP server registration (`RagServer`)
- `app/Mcp/Tools/` — MCP tool classes (7 tools)
- `app/Console/Commands/` — Artisan commands (`rag:store`, `rag:import`, `rag:search`, `rag:reindex`)
- `app/Services/Search/` — Hybrid search engine (vector + FTS + RRF + KAG)
- `app/Services/Graph/` — Knowledge graph explorer service
- `app/Services/Importing/` — Document importer service (.md/.txt splitting)
- `database/migrations/` — Postgres schema with pgvector and tsvector
- `tests/` — Pest PHP tests
- `docs/superpowers/specs/` — Design specs
- `docs/superpowers/plans/` — Implementation plans