# Vector Search Design Spec

**Goal:** Replace TF-IDF lexical search with vector (semantic) search using `sentence-transformers` embeddings stored in SQLite via `sqlite-vec`.

**Date:** 2026-07-06

---

## Context

The RAG knowledge base currently uses TF-IDF (`search_engine.py`) for search. TF-IDF is lexical — it matches exact words, not meaning. Searching "pagamento" won't find "transação". The project has entries in Portuguese (pt-BR), making semantic search valuable.

## Decision

**Vector search only** (no hybrid). Replace TF-IDF entirely with embedding-based semantic search. For a personal RAG with dozens to hundreds of entries, hybrid search adds complexity without meaningful benefit — embeddings already capture both lexical and semantic similarity.

## Tech Stack

- **Embedding model:** `paraphrase-multilingual-mpnet-base-v2` (768-dim, multilingual, ~1.1GB)
- **Vector storage:** `sqlite-vec` extension for SQLite (virtual table type `vec0`)
- **ML runtime:** `sentence-transformers` (pulls `torch` automatically)
- **Test model:** `all-MiniLM-L6-v2` (384-dim, ~80MB) — configured via `RAG_EMBEDDING_MODEL` env var to avoid downloading 1.1GB in tests

## Architecture

### New file: `rag/server/embeddings.py`

Responsible for:
- Loading the `sentence-transformers` model (lazy, cached in memory after first load)
- Generating embeddings from text (title + content combined)
- Providing the embedding dimension for the current model

```python
import os

_model = None
_model_name = os.environ.get("RAG_EMBEDDING_MODEL", "paraphrase-multilingual-mpnet-base-v2")

def get_model():
    global _model
    if _model is None:
        from sentence_transformers import SentenceTransformer
        _model = SentenceTransformer(_model_name)
    return _model

def get_embedding_dim():
    return get_model().get_sentence_embedding_dimension()

def embed_text(text):
    model = get_model()
    return model.encode(text).tolist()

def embed_query(query):
    return embed_text(query)
```

### Modified: `rag/server/db.py`

Add virtual table and embedding management functions:

```sql
CREATE VIRTUAL TABLE IF NOT EXISTS entry_embeddings USING vec0(
    entry_id TEXT PRIMARY KEY,
    embedding FLOAT[768]
);
```

**Note:** The dimension (768) is hardcoded in the schema. If the model changes, a migration is needed to recreate the table. The test model (384-dim) requires a separate table or the dimension to match — tests will use the production model dimension by creating the table with the correct size at init time.

New functions:
- `store_embedding(entry_id, embedding)` — INSERT OR REPLACE into `entry_embeddings`
- `get_embedding(entry_id)` — SELECT embedding by entry_id
- `delete_embedding(entry_id)` — DELETE from `entry_embeddings`
- `search_embeddings(query_embedding, k=10)` — `SELECT entry_id, distance FROM entry_embeddings WHERE embedding MATCH ? AND k = ? ORDER BY distance`
- `search_entries_by_embedding(query_embedding, project_id, k, category, tags)` — joins `entry_embeddings` with `knowledge_entries` to return enriched results with filters

**Embedding lifecycle:**
- **Approve** (pending → indexed) → generate and store embedding
- **Update** (title/content changed) → regenerate embedding
- **Delete** → remove embedding
- **Reject** → no embedding (only indexed entries have embeddings)
- **Approve-all** → batch generate embeddings

### Modified: `rag/server/api.py`

- `GET /api/search` — replace TF-IDF with vector search: embed query, call `db.search_entries_by_embedding()`
- `POST /api/entries/{id}/approve` — after approving, generate and store embedding
- `PUT /api/entries/{id}` — after updating, if title/content changed, regenerate embedding
- `DELETE /api/entries/{id}` — after deleting, remove embedding
- `POST /api/projects/{id}/approve-all` — after approving all, batch generate embeddings

### Deleted: `rag/server/search_engine.py`

Entire file removed. No longer needed.

### Modified: `rag/requirements.txt`

Add:
```
sentence-transformers>=3.0.0
sqlite-vec>=0.1.6
```

### Modified: `Dockerfile.api`

Add volume for HuggingFace model cache to avoid re-downloading 1.1GB on every container rebuild:
```dockerfile
ENV HF_HOME=/root/.cache/huggingface
```
And in `docker-compose.yml`, add volume mount for the API service:
```yaml
volumes:
  - ~/.rag:/root/.rag
  - ~/.cache/huggingface:/root/.cache/huggingface
```

### Modified: `rag/tests/conftest.py`

The `temp_db` fixture must load the `sqlite-vec` extension before creating the virtual table. Add `conn.enable_load_extension(True)` and `conn.load_extension("vec0")` in `get_connection()` or in `init_db()`.

### Tests

**Deleted:** `rag/tests/test_search.py` (TF-IDF tests)

**Created:** `rag/tests/test_embeddings.py`
- `test_embed_text_returns_vector` — embedding has correct dimension
- `test_store_and_get_embedding` — round-trip store/get
- `test_delete_embedding` — embedding removed
- `test_search_returns_similar` — entries with similar content rank higher
- `test_search_no_results` — empty index returns []
- `test_search_filter_by_category` — category filter works
- `test_search_filter_by_tags` — tag filter works
- `test_search_top_k` — respects top_k limit

**Modified:** `rag/tests/test_api.py`
- `TestSearch` class — update to work with vector search (entries must be approved to have embeddings)
- Remove `import search_engine` references

**Modified:** `rag/tests/test_integration.py`
- Update search tests to use vector search

## Data Flow

### Search
```
User query → api.py /api/search
  → embeddings.embed_query(query) → 768-dim vector
  → db.search_entries_by_embedding(vector, project_id, k, category, tags)
    → SELECT entry_id, distance FROM entry_embeddings WHERE embedding MATCH ? AND k = ?
    → JOIN with knowledge_entries for title, content, category, tags
    → Apply category/tag filters
  → Return results with score = 1 - distance
```

### Approve entry
```
POST /api/entries/{id}/approve
  → db.approve_entries([id])  (status → indexed)
  → embeddings.embed_text(title + " " + content)
  → db.store_embedding(id, vector)
```

### Update entry
```
PUT /api/entries/{id}
  → db.update_entry(id, ...)
  → if title or content changed:
      → embeddings.embed_text(new_title + " " + new_content)
      → db.store_embedding(id, vector)  (INSERT OR REPLACE)
```

### Delete entry
```
DELETE /api/entries/{id}
  → db.delete_embedding(id)
  → db.remove_entry(id)
```

## What Does NOT Change

- React frontend — already consumes `/api/search`, response shape stays the same (`id`, `title`, `content`, `category`, `tags`, `score`)
- Schema for `projects`, `knowledge_entries`, `tags`, `entry_tags` — unchanged
- `docker-compose.yml` structure — only adds a volume mount
- MCP server (`main.py`) — no changes
- Admin panel UI — no changes

## Migration

Existing indexed entries need embeddings generated. A one-time migration script or a `rag_reindex` MCP tool will iterate all indexed entries and generate embeddings. This can be slow (first model load + embedding generation) but only runs once.

## Risks

- **Model download size:** ~1.1GB for `mpnet-base-v2` on first run. Mitigated by Docker volume for `~/.cache/huggingface`.
- **Memory usage:** Model stays in memory after first load (~1-2GB RAM). Acceptable for a local tool.
- **`sqlite-vec` extension loading:** Requires the extension to be available at runtime. The `sqlite-vec` pip package bundles the extension, but `sqlite3` must support `load_extension`. On macOS, the system `sqlite3` may not — the `sqlite-vec` package handles this by providing a bundled SQLite.
- **Dimension mismatch in tests:** The test model (`all-MiniLM-L6-v2`) produces 384-dim vectors while production uses 768-dim. The virtual table dimension must match the model. Tests will set `RAG_EMBEDDING_MODEL=all-MiniLM-L6-v2` and the table creation must use the model's actual dimension.
