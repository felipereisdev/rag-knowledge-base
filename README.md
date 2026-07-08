# RAG Knowledge Base

A per-project knowledge base RAG (Retrieval-Augmented Generation) for AI coding assistants, with an **approval workflow** so you control what goes into the knowledge base.

**Phase 1 (current):** Laravel 13 + Martis admin panel with CRUD for projects, entries, tags, entities, relations, project paths, and chunk embeddings. Search engine, MCP server, and embedder sidecar come in Phases 2-4.

## Requirements

- PHP 8.3+
- Composer 2.x
- Docker (for Postgres+pgvector)

## Quick start

```bash
# 1. Start Postgres+pgvector
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
| `RAG_EMBEDDING_MODEL` | `paraphrase-multilingual-mpnet-base-v2` | Embedding model (used in Phase 2) |
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
- `database/migrations/` — Postgres schema with pgvector and tsvector
- `tests/` — Pest PHP tests
- `docs/superpowers/specs/` — Design specs
- `docs/superpowers/plans/` — Implementation plans