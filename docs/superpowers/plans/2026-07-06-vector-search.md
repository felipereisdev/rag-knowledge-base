# Vector Search Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace TF-IDF lexical search with `sentence-transformers` vector embeddings stored in SQLite via `sqlite-vec`.

**Architecture:** `embeddings.py` wraps a `sentence-transformers` model (lazy-loaded). `db.py` adds a `vec0` virtual table for storage. `api.py` uses vector search instead of TF-IDF and manages embeddings on approve/update/delete. `search_engine.py` and its tests are deleted.

**Tech Stack:** Python 3.12, sentence-transformers >=3.0.0, sqlite-vec >=0.1.6, SQLite vec0 virtual table

---

## File Structure

```
rag/
├── server/
│   ├── embeddings.py          ← CREATE: model wrapper (lazy load, embed text)
│   ├── api.py                 ← MODIFY: search → vector, manage embeddings
│   ├── db.py                  ← MODIFY: entry_embeddings table + CRUD/search
│   ├── search_engine.py       ← DELETE (replaced by embeddings + vec0)
│   └── main.py                ← no changes
├── tests/
│   ├── conftest.py            ← MODIFY: load sqlite-vec extension
│   ├── test_api.py            ← MODIFY: update search tests
│   ├── test_embeddings.py     ← CREATE: embedding CRUD + search tests
│   ├── test_search.py         ← DELETE (TF-IDF tests)
│   └── test_integration.py    ← MODIFY: update search integration tests
├── requirements.txt           ← MODIFY: add sentence-transformers, sqlite-vec
├── Dockerfile.api             ← MODIFY: add HF_HOME env var
└── docker-compose.yml         ← MODIFY: add huggingface cache volume
```

---

## Task 1: Foundation — embeddings.py + db.py + conftest

**Files:**
- Create: `rag/server/embeddings.py`
- Modify: `rag/requirements.txt`
- Modify: `rag/tests/conftest.py`
- Modify: `rag/server/db.py`
- Create: `rag/tests/test_embeddings.py`

- [ ] **Step 1: Create requirements.txt**

Append to `rag/requirements.txt`:
```
sentence-transformers>=3.0.0
sqlite-vec>=0.1.6
```

- [ ] **Step 2: Install dependencies**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag && pip3 install -r requirements.txt`
Expected: sentence-transformers + sqlite-vec installed (may take a minute to pull torch)

- [ ] **Step 3: Create `rag/server/embeddings.py`**

Create with the following content — this module provides lazy-loaded model access without importing torch at module level:

```python
"""Sentence-transformers embedding model wrapper. Model loads lazily on first use."""

import logging
import os

EMBEDDING_DIM = int(os.environ.get("RAG_EMBEDDING_DIM", "768"))
MODEL_NAME = os.environ.get(
    "RAG_EMBEDDING_MODEL",
    "paraphrase-multilingual-mpnet-base-v2",
)

_model = None


def get_model():
    global _model
    if _model is None:
        from sentence_transformers import SentenceTransformer

        logging.getLogger("sentence_transformers").setLevel(logging.WARNING)
        _model = SentenceTransformer(MODEL_NAME)
    return _model


def embed_text(text):
    if not text:
        return [0.0] * EMBEDDING_DIM
    model = get_model()
    return model.encode(text).tolist()


def embed_query(query):
    return embed_text(query)
```

Note: `EMBEDDING_DIM` defaults to 768 (mpnet-base-v2 output). Tests set `RAG_EMBEDDING_DIM=384` to match all-MiniLM-L6-v2. This avoids loading the model just to get the dimension.

- [ ] **Step 4: Update conftest.py to load sqlite-vec**

Replace `rag/tests/conftest.py` with this version that loads the vector extension:

```python
"""Shared test fixtures for RAG knowledge base tests."""
import os
import sys
import tempfile
import pytest

# Make server modules importable
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "server"))


@pytest.fixture(autouse=True)
def clean_env():
    """Force test model and dimension for all tests."""
    old_model = os.environ.get("RAG_EMBEDDING_MODEL")
    old_dim = os.environ.get("RAG_EMBEDDING_DIM")
    os.environ["RAG_EMBEDDING_MODEL"] = "all-MiniLM-L6-v2"
    os.environ["RAG_EMBEDDING_DIM"] = "384"
    yield
    if old_model:
        os.environ["RAG_EMBEDDING_MODEL"] = old_model
    else:
        os.environ.pop("RAG_EMBEDDING_MODEL", None)
    if old_dim:
        os.environ["RAG_EMBEDDING_DIM"] = old_dim
    else:
        os.environ.pop("RAG_EMBEDDING_DIM", None)


@pytest.fixture
def temp_db(monkeypatch):
    """Use a temporary database file for each test."""
    with tempfile.TemporaryDirectory() as tmpdir:
        db_path = os.path.join(tmpdir, "test_knowledge.db")
        monkeypatch.setattr("db.DB_PATH", db_path)
        monkeypatch.setattr("db.DATA_DIR", tmpdir)
        import db
        db.init_db()
        yield db


@pytest.fixture
def client(temp_db):
    """FastAPI TestClient with a temp database."""
    from fastapi.testclient import TestClient
    import api
    api.db = temp_db
    with TestClient(api.app) as c:
        yield c
```

- [ ] **Step 5: Write failing tests for embedding functions**

Create `rag/tests/test_embeddings.py`:

```python
"""Tests for sentence-transformers embedding storage and vector search."""
import pytest


class TestEmbedText:
    def test_embed_text_returns_vector(self, temp_db):
        import embeddings
        vec = embeddings.embed_text("test text")
        assert len(vec) == 384
        assert all(isinstance(v, float) for v in vec)

    def test_embed_empty_text(self, temp_db):
        import embeddings
        vec = embeddings.embed_text("")
        assert len(vec) == 384


class TestEmbeddingCRUD:
    def test_store_and_get_embedding(self, temp_db):
        import embeddings
        vec = embeddings.embed_text("hello world")
        temp_db.store_embedding("e1", vec)
        stored = temp_db.get_embedding("e1")
        assert stored is not None
        assert stored["entry_id"] == "e1"
        assert len(stored["embedding"]) == 384

    def test_delete_embedding(self, temp_db):
        import embeddings
        vec = embeddings.embed_text("test")
        temp_db.store_embedding("e1", vec)
        temp_db.delete_embedding("e1")
        assert temp_db.get_embedding("e1") is None

    def test_replace_embedding(self, temp_db):
        import embeddings
        temp_db.store_embedding("e1", embeddings.embed_text("first"))
        temp_db.store_embedding("e1", embeddings.embed_text("second"))
        stored = temp_db.get_embedding("e1")
        assert stored is not None


class TestVectorSearch:
    def _setup(self, temp_db):
        import embeddings
        e1 = "order-approval"
        e2 = "auth-jwt"
        temp_db.store_embedding(e1, embeddings.embed_text("Orders over 1000 need manager approval"))
        temp_db.store_embedding(e2, embeddings.embed_text("JWT authentication with refresh tokens"))
        return e1, e2

    def test_search_returns_similar(self, temp_db):
        import embeddings
        e1, _ = self._setup(temp_db)
        query_vec = embeddings.embed_query("order approval workflow")
        results = temp_db.search_embeddings(query_vec, k=5)
        assert len(results) >= 1
        assert results[0]["entry_id"] == e1

    def test_search_top_k(self, temp_db):
        import embeddings
        self._setup(temp_db)
        query_vec = embeddings.embed_query("test")
        results = temp_db.search_embeddings(query_vec, k=1)
        assert len(results) <= 1

    def test_search_no_results(self, temp_db):
        import embeddings
        query_vec = embeddings.embed_query("anything")
        results = temp_db.search_embeddings(query_vec, k=5)
        assert results == []

    def test_search_entries_by_embedding_with_filters(self, temp_db):
        import embeddings
        """Create a project and entries via db then search"""
        temp_db.upsert_project("proj1", "Proj1", "/tmp/p1")
        temp_db.upsert_project("proj2", "Proj2", "/tmp/p2")
        e1 = temp_db.store_knowledge_entry("proj1", "Order rule", "Orders over 1000 need approval",
                                            category="business-rule", tags=["orders"])
        e2 = temp_db.store_knowledge_entry("proj2", "Auth architecture", "JWT tokens in Redis",
                                            category="architecture", tags=["auth"])
        # Store embeddings for both
        vec1 = embeddings.embed_text("Orders over 1000 need approval")
        vec2 = embeddings.embed_text("JWT tokens in Redis")
        temp_db.store_embedding(e1, vec1)
        temp_db.store_embedding(e2, vec2)
        query_vec = embeddings.embed_query("approval")
        results = temp_db.search_entries_by_embedding(query_vec, project_id="proj1", k=5)
        assert len(results) == 1
        assert results[0]["id"] == e1
```

- [ ] **Step 6: Run test to verify they fail**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag && RAG_EMBEDDING_MODEL=all-MiniLM-L6-v2 RAG_EMBEDDING_DIM=384 python3 -m pytest tests/test_embeddings.py -v`
Expected: FAIL — db functions (store_embedding, get_embedding, etc.) not defined

- [ ] **Step 7: Update db.py with embedding table + functions**

In `rag/server/db.py`:

**a) Add import and sqlite-vec loading in `get_connection()`:**

At the top of the file, add:
```python
import embeddings
```

In `get_connection()`, after `conn.execute("PRAGMA foreign_keys=ON")`, add:
```python
    try:
        import sqlite_vec
        sqlite_vec.load(conn)
    except Exception:
        pass
```

**b) Add embedding table creation in `init_db()`:**

After the existing tables are created and before `conn.commit()`, add:
```python
        dim = embeddings.EMBEDDING_DIM
        conn.execute(f"""
            CREATE VIRTUAL TABLE IF NOT EXISTS entry_embeddings USING vec0(
                entry_id TEXT PRIMARY KEY,
                embedding FLOAT[{dim}]
            )
        """)
```

**c) Add embedding CRUD and search functions after the `get_project_stats` function (before the end of the file):**

```python
# ---- Vector embedding operations ----

def store_embedding(entry_id, embedding):
    """Store or replace an entry's embedding."""
    import struct
    blob = struct.pack(f"{len(embedding)}f", *embedding)
    conn = get_connection()
    try:
        conn.execute(
            "INSERT OR REPLACE INTO entry_embeddings (entry_id, embedding) VALUES (?, ?)",
            (entry_id, blob),
        )
        conn.commit()
    finally:
        conn.close()


def get_embedding(entry_id):
    """Get an entry's embedding as (entry_id, embedding_list) or None."""
    import struct
    conn = get_connection()
    try:
        row = conn.execute(
            "SELECT entry_id, embedding FROM entry_embeddings WHERE entry_id = ?",
            (entry_id,),
        ).fetchone()
        if not row:
            return None
        blob = row["embedding"]
        dim = len(blob) // 4
        embedding = list(struct.unpack(f"{dim}f", blob))
        return {"entry_id": row["entry_id"], "embedding": embedding}
    finally:
        conn.close()


def delete_embedding(entry_id):
    """Remove an entry's embedding."""
    conn = get_connection()
    try:
        conn.execute("DELETE FROM entry_embeddings WHERE entry_id = ?", (entry_id,))
        conn.commit()
    finally:
        conn.close()


def search_embeddings(query_embedding, k=10):
    """Find nearest neighbors by cosine similarity. Returns list of {entry_id, distance}."""
    import struct
    blob = struct.pack(f"{len(query_embedding)}f", *query_embedding)
    conn = get_connection()
    try:
        rows = conn.execute(
            "SELECT entry_id, distance FROM entry_embeddings WHERE embedding MATCH ? AND k = ? ORDER BY distance",
            (blob, k),
        ).fetchall()
        return [dict(r) for r in rows]
    finally:
        conn.close()


def search_entries_by_embedding(query_embedding, project_id, k=10, category=None, tags=None):
    """Search entries by embedding similarity with project/category/tag filtering."""
    neighbors = search_embeddings(query_embedding, k=k)
    if not neighbors:
        return []
    entry_ids = [n["entry_id"] for n in neighbors]
    placeholders = ",".join("?" for _ in entry_ids)
    conn = get_connection()
    try:
        sql = f"""
            SELECT e.*, ke.title, ke.content, ke.category, ke.project_id, ke.status
            FROM entry_embeddings e
            JOIN knowledge_entries ke ON ke.id = e.entry_id
            WHERE e.entry_id IN ({placeholders})
            AND ke.project_id = ?
            AND ke.status = 'indexed'
        """
        params = entry_ids + [project_id]
        if category:
            sql += " AND ke.category = ?"
            params.append(category)
        if tags:
            for tag in tags:
                sql += " AND e.entry_id IN (SELECT et.entry_id FROM entry_tags et JOIN tags t ON t.id = et.tag_id WHERE t.name = ? AND t.project_id = ?)"
                params.extend([tag.lower(), project_id])
        sql += " ORDER BY CASE e.entry_id"
        for i, eid in enumerate(entry_ids):
            sql += f" WHEN ? THEN {i}"
            params.append(eid)
        sql += " END"
        rows = conn.execute(sql, params).fetchall()
        score_map = {n["entry_id"]: round(1 - n["distance"], 4) for n in neighbors}
        results = []
        for r in rows:
            entry = dict(r)
            entry["tags"] = get_tags_for_entry(entry["entry_id"])
            entry["score"] = score_map.get(entry["entry_id"], 0)
            results.append(entry)
        return results
    finally:
        conn.close()
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag && RAG_EMBEDDING_MODEL=all-MiniLM-L6-v2 RAG_EMBEDDING_DIM=384 python3 -m pytest tests/test_embeddings.py -v`
Expected: PASS — all 8 embedding tests green

- [ ] **Step 9: Commit**

```bash
cd /Users/freis/Projects/PERSONAL/rag
git add rag/server/embeddings.py rag/requirements.txt rag/tests/conftest.py rag/server/db.py rag/tests/test_embeddings.py
git commit -m "feat: sentence-transformers embeddings with sqlite-vec storage"
```

---

## Task 2: API Lifecycle — Manage Embeddings on Approve/Update/Delete + Cleanup

**Files:**
- Modify: `rag/server/api.py` (approve/update/delete → manage embeddings)
- Modify: `rag/tests/test_api.py` (add lifecycle tests)
- Delete: `rag/server/search_engine.py`
- Delete: `rag/tests/test_search.py`

- [ ] **Step 1: Write failing tests for approve/update embedding lifecycle**

Append to `rag/tests/test_api.py` as new tests. After the `TestTags` class, add:

```python
class TestEmbeddingLifecycle:
    def test_approve_generates_embedding(self, client):
        client.post("/api/projects", json={
            "id": "test-proj", "name": "Test", "root_path": "/tmp/test",
        })
        create = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Rule", "content": "content",
        }).json()
        eid = create["id"]
        client.post(f"/api/entries/{eid}/approve")
        import db
        emb = db.get_embedding(eid)
        assert emb is not None

    def test_update_regenerates_embedding(self, client):
        client.post("/api/projects", json={
            "id": "test-proj", "name": "Test", "root_path": "/tmp/test",
        })
        create = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Rule", "content": "content",
        }).json()
        eid = create["id"]
        client.post(f"/api/entries/{eid}/approve")
        import db
        emb_before = db.get_embedding(eid)
        client.put(f"/api/entries/{eid}", json={"title": "New Title", "content": "new content"})
        emb_after = db.get_embedding(eid)
        assert emb_after is not None
        assert emb_after["embedding"] != emb_before["embedding"]

    def test_delete_removes_embedding(self, client):
        client.post("/api/projects", json={
            "id": "test-proj", "name": "Test", "root_path": "/tmp/test",
        })
        create = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Rule", "content": "content",
        }).json()
        eid = create["id"]
        client.post(f"/api/entries/{eid}/approve")
        client.delete(f"/api/entries/{eid}")
        import db
        assert db.get_embedding(eid) is None
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag && RAG_EMBEDDING_MODEL=all-MiniLM-L6-v2 RAG_EMBEDDING_DIM=384 python3 -m pytest tests/test_api.py::TestEmbeddingLifecycle -v`
Expected: FAIL — approve/update/delete not generating/removing embeddings

- [ ] **Step 3: Update api.py endpoints to manage embeddings**

In `rag/server/api.py`:

**a) Add import at top of file (after `import db`):**
```python
import embeddings
```

**b) Update `approve_entry` endpoint — after `db.approve_entries([entry_id])`, add:**
```python
    vec = embeddings.embed_text(entry["title"] + " " + entry["content"])
    db.store_embedding(entry_id, vec)
```

**c) Update `update_entry` endpoint — after the `db.update_entry(...)` call, before the return, add:**
```python
    if entry["status"] == "indexed":
        vec = embeddings.embed_text(update.title or entry["title"]
                                    + " " + update.content or entry["content"])
        db.store_embedding(entry_id, vec)
```

**d) Update `delete_entry` endpoint — before `db.remove_entry(entry_id)`, add:**
```python
    db.delete_embedding(entry_id)
```

**e) Update `approve_all` endpoint — after `db.approve_entries(entry_ids)`, add:**
```python
    for eid in entry_ids:
        entry_approved = db.get_entry(eid)
        if entry_approved:
            vec = embeddings.embed_text(entry_approved["title"] + " " + entry_approved["content"])
            db.store_embedding(eid, vec)
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag && RAG_EMBEDDING_MODEL=all-MiniLM-L6-v2 RAG_EMBEDDING_DIM=384 python3 -m pytest tests/test_api.py::TestEmbeddingLifecycle -v`
Expected: PASS — all lifecycle tests green

- [ ] **Step 5: Delete search_engine.py and test_search.py**

```bash
rm /Users/freis/Projects/PERSONAL/rag/rag/server/search_engine.py
rm /Users/freis/Projects/PERSONAL/rag/rag/tests/test_search.py
```

- [ ] **Step 6: Update test_integration.py**

Replace `rag/tests/test_integration.py` with a version that uses vector search via db functions instead of TF-IDF:

```python
"""Integration tests for the full knowledge base workflow."""
import os
import tempfile
import pytest


class TestFullWorkflow:
    def test_store_approve_search(self, temp_db):
        import embeddings
        temp_db.upsert_project("shop", "Shop App", "/tmp/shop", "E-commerce app", "")

        eid1 = temp_db.store_knowledge_entry(
            "shop", "Order approval rule",
            "Orders over 1000 require manager approval",
            "business-rule", tags=["orders", "approval"]
        )
        eid2 = temp_db.store_knowledge_entry(
            "shop", "Auth architecture",
            "JWT with refresh tokens stored in Redis",
            "architecture", tags=["auth", "security"]
        )

        temp_db.approve_entries([eid1, eid2])

        vec1 = embeddings.embed_text("Orders over 1000 require manager approval")
        vec2 = embeddings.embed_text("JWT with refresh tokens stored in Redis")
        temp_db.store_embedding(eid1, vec1)
        temp_db.store_embedding(eid2, vec2)

        query_vec = embeddings.embed_query("order approval")
        results = temp_db.search_entries_by_embedding(query_vec, project_id="shop", k=5)
        assert len(results) >= 1
        assert results[0]["title"] == "Order approval rule"

        query_vec = embeddings.embed_query("auth")
        results = temp_db.search_entries_by_embedding(query_vec, project_id="shop", k=5)
        assert len(results) >= 1
        assert results[0]["title"] == "Auth architecture"

    def test_import_markdown_and_search(self, temp_db):
        import embeddings
        temp_db.upsert_project("docs", "Docs", "/tmp/docs", "", "")

        md_content = """---
category: business-rule
tags: payments, stripe
---

# Payment Rule

All payments go through Stripe. Refunds within 30 days.

# Refund Policy

Full refund within 30 days. Partial after that.
"""
        with tempfile.NamedTemporaryFile(mode="w", suffix=".md", delete=False) as f:
            f.write(md_content)
            filepath = f.name

        try:
            import doc_import
            entry_ids = doc_import.import_document(temp_db, "docs", filepath)
            assert len(entry_ids) == 2

            temp_db.approve_entries(entry_ids)

            for eid in entry_ids:
                entry = temp_db.get_entry(eid)
                vec = embeddings.embed_text(entry["title"] + " " + entry["content"])
                temp_db.store_embedding(eid, vec)

            query_vec = embeddings.embed_query("stripe payment")
            results = temp_db.search_entries_by_embedding(query_vec, project_id="docs", k=5)
            assert len(results) >= 1
            assert "Payment" in results[0]["title"]

            query_vec = embeddings.embed_query("refund")
            results = temp_db.search_entries_by_embedding(query_vec, project_id="docs", k=5)
            assert len(results) >= 1
        finally:
            os.unlink(filepath)

- [ ] **Step 7: Run all existing tests (excluding search tests we'll update in Task 3)**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag && RAG_EMBEDDING_MODEL=all-MiniLM-L6-v2 RAG_EMBEDDING_DIM=384 python3 -m pytest tests/test_db.py tests/test_import.py tests/test_embeddings.py tests/test_api.py::TestProjects tests/test_api.py::TestEntries tests/test_api.py::TestTags tests/test_api.py::TestEmbeddingLifecycle -v`
Expected: All pass

- [ ] **Step 8: Commit**

```bash
cd /Users/freis/Projects/PERSONAL/rag
git add rag/server/api.py rag/tests/test_api.py rag/tests/test_integration.py
git rm rag/server/search_engine.py
git rm rag/tests/test_search.py
git commit -m "feat: manage embeddings on approve/update/delete; remove TF-IDF"
```

---

## Task 3: API Search → Vector Search

**Files:**
- Modify: `rag/server/api.py` (search endpoint)
- Modify: `rag/tests/test_api.py` (TestSearch)

- [ ] **Step 1: Write failing search tests**

Find the `TestSearch` class in `rag/tests/test_api.py` and replace it with:

```python
class TestSearch:
    def _setup_with_data(self, client):
        client.post("/api/projects", json={
            "id": "test-proj", "name": "Test", "root_path": "/tmp/test",
        })
        e1 = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Order approval rule",
            "content": "Orders over 1000 need manager approval",
            "category": "business-rule", "tags": ["orders"],
        }).json()["id"]
        e2 = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Auth architecture",
            "content": "JWT with refresh tokens in Redis",
            "category": "architecture", "tags": ["auth"],
        }).json()["id"]
        client.post(f"/api/entries/{e1}/approve")
        client.post(f"/api/entries/{e2}/approve")

    def test_search_returns_results(self, client):
        self._setup_with_data(client)
        resp = client.get("/api/search?q=order+approval&project_id=test-proj")
        assert resp.status_code == 200
        data = resp.json()
        assert len(data) >= 1
        assert data[0]["title"] == "Order approval rule"

    def test_search_no_results(self, client):
        self._setup_with_data(client)
        resp = client.get("/api/search?q=nonexistent&project_id=test-proj")
        assert resp.status_code == 200
        assert resp.json() == []

    def test_search_filter_by_category(self, client):
        self._setup_with_data(client)
        resp = client.get("/api/search?q=approval&project_id=test-proj&category=architecture")
        assert resp.status_code == 200
        for result in resp.json():
            assert result["category"] == "architecture"

    def test_search_top_k(self, client):
        self._setup_with_data(client)
        resp = client.get("/api/search?q=approval&project_id=test-proj&top_k=1")
        assert resp.status_code == 200
        assert len(resp.json()) <= 1
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag && RAG_EMBEDDING_MODEL=all-MiniLM-L6-v2 RAG_EMBEDDING_DIM=384 python3 -m pytest tests/test_api.py::TestSearch -v`
Expected: FAIL — search endpoint still uses TF-IDF (if the old import still works) or returns empty results (if search is broken)

- [ ] **Step 3: Update api.py search endpoint**

Replace the `# ---- Search and tags ----` section in `rag/server/api.py`:

```python
# ---- Search and tags ----

@app.get("/api/search")
def search(
    q: str = Query(...),
    project_id: str = Query(...),
    category: str | None = None,
    tags: list[str] | None = Query(None),
    top_k: int = 5,
):
    if not q.strip():
        return []
    query_vec = embeddings.embed_query(q)
    results = db.search_entries_by_embedding(
        query_vec,
        project_id=project_id,
        k=top_k,
        category=category,
        tags=tags,
    )
    return results
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag && RAG_EMBEDDING_MODEL=all-MiniLM-L6-v2 RAG_EMBEDDING_DIM=384 python3 -m pytest tests/test_api.py::TestSearch -v`
Expected: PASS — all search tests green

- [ ] **Step 5: Run all tests**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag && RAG_EMBEDDING_MODEL=all-MiniLM-L6-v2 RAG_EMBEDDING_DIM=384 python3 -m pytest tests/ -v`
Expected: ALL tests pass

- [ ] **Step 6: Commit**

```bash
cd /Users/freis/Projects/PERSONAL/rag
git add rag/server/api.py rag/tests/test_api.py
git commit -m "feat: vector search in API endpoint (TF-IDF replaced)"
```

**Files:**
- Modify: `rag/server/api.py` (approve/update/delete → manage embeddings)
- Modify: `rag/tests/test_api.py` (update tests)
- Delete: `rag/server/search_engine.py`
- Delete: `rag/tests/test_search.py`

- [ ] **Step 1: Write failing tests for approve/update embedding lifecycle**

Append to `rag/tests/test_api.py` as new tests in `TestApprovals` or as separate class:

Find the `test_approve_entry` test and after the `TestTags` class, add:

```python
class TestEmbeddingLifecycle:
    def test_approve_generates_embedding(self, client):
        client.post("/api/projects", json={
            "id": "test-proj", "name": "Test", "root_path": "/tmp/test",
        })
        create = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Rule", "content": "content",
        }).json()
        eid = create["id"]
        client.post(f"/api/entries/{eid}/approve")
        import db
        emb = db.get_embedding(eid)
        assert emb is not None

    def test_update_regenerates_embedding(self, client):
        client.post("/api/projects", json={
            "id": "test-proj", "name": "Test", "root_path": "/tmp/test",
        })
        create = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Rule", "content": "content",
        }).json()
        eid = create["id"]
        client.post(f"/api/entries/{eid}/approve")
        import db
        emb_before = db.get_embedding(eid)
        client.put(f"/api/entries/{eid}", json={"title": "New Title", "content": "new content"})
        emb_after = db.get_embedding(eid)
        assert emb_after is not None
        assert emb_after["embedding"] != emb_before["embedding"]

    def test_delete_removes_embedding(self, client):
        client.post("/api/projects", json={
            "id": "test-proj", "name": "Test", "root_path": "/tmp/test",
        })
        create = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Rule", "content": "content",
        }).json()
        eid = create["id"]
        client.post(f"/api/entries/{eid}/approve")
        client.delete(f"/api/entries/{eid}")
        import db
        assert db.get_embedding(eid) is None
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag && RAG_EMBEDDING_MODEL=all-MiniLM-L6-v2 RAG_EMBEDDING_DIM=384 python3 -m pytest tests/test_api.py::TestEmbeddingLifecycle -v`
Expected: FAIL — approve/update/delete not generating/removing embeddings

- [ ] **Step 3: Update api.py endpoints to manage embeddings**

In `rag/server/api.py`:

**a) Add imports at top:**
```python
import embeddings
```

**b) Update `approve_entry` endpoint — after `db.approve_entries([entry_id])`, add:**
```python
    vec = embeddings.embed_text(entry["title"] + " " + entry["content"])
    db.store_embedding(entry_id, vec)
```

**c) Update `update_entry` endpoint — after the `db.update_entry(...)` call, add:**
```python
    entry_new = db.get_entry(entry_id)
    if entry_new["status"] == "indexed":
        vec = embeddings.embed_text(entry_new["title"] + " " + entry_new["content"])
        db.store_embedding(entry_id, vec)
```

**d) Update `delete_entry` endpoint — before `db.remove_entry(entry_id)`, add:**
```python
    db.delete_embedding(entry_id)
```

**e) Update `approve_all` endpoint — after `db.approve_entries(entry_ids)`, add:**
```python
    for eid in entry_ids:
        entry = db.get_entry(eid)
        if entry:
            vec = embeddings.embed_text(entry["title"] + " " + entry["content"])
            db.store_embedding(eid, vec)
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag && RAG_EMBEDDING_MODEL=all-MiniLM-L6-v2 RAG_EMBEDDING_DIM=384 python3 -m pytest tests/test_api.py -v`
Expected: PASS — all API tests including lifecycle tests green

- [ ] **Step 5: Delete search_engine.py and test_search.py**

```bash
rm /Users/freis/Projects/PERSONAL/rag/rag/server/search_engine.py
rm /Users/freis/Projects/PERSONAL/rag/rag/tests/test_search.py
```

- [ ] **Step 6: Update test_integration.py**

In `rag/tests/test_integration.py`, remove the line `import search_engine` if present. The tests don't need changes otherwise — they use the API endpoint which now uses vector search.

- [ ] **Step 7: Run all tests**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag && RAG_EMBEDDING_MODEL=all-MiniLM-L6-v2 RAG_EMBEDDING_DIM=384 python3 -m pytest tests/ -v`
Expected: All tests pass (excluding removed test_search.py)

- [ ] **Step 8: Commit**

```bash
cd /Users/freis/Projects/PERSONAL/rag
git add rag/server/api.py rag/tests/test_api.py rag/tests/test_integration.py
git rm rag/server/search_engine.py
git rm rag/tests/test_search.py
git commit -m "feat: manage embeddings on approve/update/delete; remove TF-IDF"
```

---

## Task 4: Docker + MCP Reindex Tool + Final Verification

**Files:**
- Modify: `Dockerfile.api`
- Modify: `docker-compose.yml`
- Modify: `rag/server/main.py` (add reindex tool)

- [ ] **Step 1: Update Dockerfile.api**

Add `ENV HF_HOME=/root/.cache/huggingface` after the existing `ENV` lines. So `Dockerfile.api` becomes:

```dockerfile
FROM python:3.12-slim

WORKDIR /app

COPY rag/requirements.txt /app/requirements.txt
RUN pip3 install --no-cache-dir -r requirements.txt

COPY rag/server/ /app/server/

ENV PYTHONUNBUFFERED=1
ENV PYTHONPATH=/app/server
ENV HF_HOME=/root/.cache/huggingface

EXPOSE 8000

CMD ["uvicorn", "server.api:app", "--host", "0.0.0.0", "--port", "8000"]
```

- [ ] **Step 2: Update docker-compose.yml**

Add the HuggingFace cache volume to the API service. Replace the `volumes:` block:

```yaml
    volumes:
      - ~/.rag:/root/.rag
      - ~/.cache/huggingface:/root/.cache/huggingface
```

- [ ] **Step 3: Run all tests**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag && RAG_EMBEDDING_MODEL=all-MiniLM-L6-v2 RAG_EMBEDDING_DIM=384 python3 -m pytest tests/ -v`
Expected: ALL tests pass

- [ ] **Step 4: Verify MCP server starts**

Run: `echo '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' | python3 /Users/freis/Projects/PERSONAL/rag/rag/server/main.py 2>/dev/null | head -1`
Expected: JSON response with `"name":"rag"`

- [ ] **Step 5: Commit**

```bash
cd /Users/freis/Projects/PERSONAL/rag
git add Dockerfile.api docker-compose.yml
git commit -m "chore: add HuggingFace cache volume to Docker config"
```
