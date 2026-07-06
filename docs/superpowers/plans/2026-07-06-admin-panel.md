# RAG Admin Panel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the single-page approval UI with a full admin panel: React SPA + FastAPI REST API, served in separate Docker containers.

**Architecture:** FastAPI REST API (`api.py`) replaces `web_ui.py`, exposing CRUD endpoints for projects and entries, search, and approval workflow. React + Vite + shadcn/ui SPA served by nginx, proxying `/api/*` to the FastAPI container. MCP server (`main.py`) continues running via stdio for assistants, starting FastAPI in a daemon thread.

**Tech Stack:** Python 3.12, FastAPI, uvicorn, React 18, Vite, TypeScript, Tailwind CSS, shadcn/ui, nginx, Docker.

---

## File Structure

```
rag/
├── server/
│   ├── main.py              ← MODIFY: replace web_ui with api
│   ├── api.py               ← CREATE: FastAPI REST API
│   ├── db.py                ← existing (no changes)
│   ├── search_engine.py     ← existing (no changes)
│   ├── doc_import.py        ← existing (no changes)
│   ├── web_ui.py            ← DELETE
│   └── templates/
│       └── approval.html    ← DELETE
├── web/                     ← CREATE: React frontend
│   ├── package.json
│   ├── vite.config.ts
│   ├── tsconfig.json
│   ├── tailwind.config.ts
│   ├── postcss.config.js
│   ├── components.json
│   ├── index.html
│   └── src/
│       ├── main.tsx
│       ├── App.tsx
│       ├── index.css
│       ├── lib/
│       │   ├── api.ts
│       │   └── utils.ts
│       ├── components/
│       │   ├── Layout.tsx
│       │   ├── EntryForm.tsx
│       │   └── ui/           ← shadcn/ui components
│       └── pages/
│           ├── Dashboard.tsx
│           ├── Projects.tsx
│           ├── Entries.tsx
│           ├── EntryDetail.tsx
│           ├── NewEntry.tsx
│           ├── Approvals.tsx
│           └── Search.tsx
├── tests/
│   ├── conftest.py          ← MODIFY: add api fixture
│   └── test_api.py          ← CREATE: API endpoint tests
├── Dockerfile.api           ← CREATE
├── Dockerfile.web           ← CREATE
├── Dockerfile               ← DELETE (replaced by Dockerfile.api + Dockerfile.web)
├── docker-compose.yml       ← MODIFY: 2 services
├── nginx.conf               ← CREATE
└── requirements.txt         ← CREATE: Python deps for API
```

---

## Task 1: FastAPI API — Setup and Project Endpoints

**Files:**
- Create: `rag/requirements.txt`
- Create: `rag/server/api.py`
- Create: `rag/tests/test_api.py`
- Modify: `rag/tests/conftest.py`

- [ ] **Step 1: Create requirements.txt**

Create `rag/requirements.txt`:

```
fastapi>=0.115.0
uvicorn[standard]>=0.30.0
pytest>=8.0.0
httpx>=0.27.0
```

- [ ] **Step 2: Install dependencies**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag && pip3 install -r requirements.txt`

- [ ] **Step 3: Add API fixture to conftest.py**

Replace `rag/tests/conftest.py` with:

```python
"""Shared test fixtures for RAG knowledge base tests."""
import os
import sys
import tempfile
import pytest

# Make server modules importable
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "server"))


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

- [ ] **Step 4: Write failing tests for project endpoints**

Create `rag/tests/test_api.py`:

```python
"""Tests for the FastAPI REST API."""
import pytest


class TestProjects:
    def test_list_projects_empty(self, client):
        resp = client.get("/api/projects")
        assert resp.status_code == 200
        assert resp.json() == []

    def test_create_project(self, client):
        resp = client.post("/api/projects", json={
            "id": "test-proj",
            "name": "Test Project",
            "root_path": "/tmp/test",
            "description": "A test project",
            "language": "en",
        })
        assert resp.status_code == 201
        data = resp.json()
        assert data["id"] == "test-proj"
        assert data["name"] == "Test Project"
        assert data["language"] == "en"

    def test_get_project(self, client):
        client.post("/api/projects", json={
            "id": "test-proj", "name": "Test", "root_path": "/tmp/test",
        })
        resp = client.get("/api/projects/test-proj")
        assert resp.status_code == 200
        assert resp.json()["name"] == "Test"

    def test_get_project_not_found(self, client):
        resp = client.get("/api/projects/nonexistent")
        assert resp.status_code == 404

    def test_update_project(self, client):
        client.post("/api/projects", json={
            "id": "test-proj", "name": "Test", "root_path": "/tmp/test",
        })
        resp = client.put("/api/projects/test-proj", json={
            "name": "Updated", "language": "pt-BR",
        })
        assert resp.status_code == 200
        assert resp.json()["name"] == "Updated"
        assert resp.json()["language"] == "pt-BR"

    def test_delete_project(self, client):
        client.post("/api/projects", json={
            "id": "test-proj", "name": "Test", "root_path": "/tmp/test",
        })
        resp = client.delete("/api/projects/test-proj")
        assert resp.status_code == 204
        assert client.get("/api/projects/test-proj").status_code == 404

    def test_list_projects_with_stats(self, client):
        client.post("/api/projects", json={
            "id": "p1", "name": "P1", "root_path": "/tmp/p1",
        })
        resp = client.get("/api/projects")
        assert resp.status_code == 200
        data = resp.json()
        assert len(data) == 1
        assert data[0]["indexed_count"] == 0
        assert data[0]["pending_count"] == 0
```

- [ ] **Step 5: Run tests to verify they fail**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag && python3 -m pytest tests/test_api.py -v`
Expected: FAIL — `api` module not found

- [ ] **Step 6: Write api.py with project endpoints**

Create `rag/server/api.py`:

```python
"""FastAPI REST API for the RAG admin panel."""
import threading
import uvicorn
from fastapi import FastAPI, HTTPException, Query
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel

import db

app = FastAPI(title="RAG Admin API", version="0.4.0")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)


# ---- Models ----

class ProjectCreate(BaseModel):
    id: str
    name: str
    root_path: str
    description: str = ""
    project_type: str = ""
    language: str = "en"


class ProjectUpdate(BaseModel):
    name: str | None = None
    description: str | None = None
    language: str | None = None


class EntryCreate(BaseModel):
    project_id: str
    title: str
    content: str
    category: str = "insight"
    tags: list[str] = []


class EntryUpdate(BaseModel):
    title: str | None = None
    content: str | None = None
    category: str | None = None
    tags: list[str] | None = None


# ---- Project endpoints ----

@app.get("/api/projects")
def list_projects():
    return db.list_projects()


@app.post("/api/projects", status_code=201)
def create_project(proj: ProjectCreate):
    db.upsert_project(
        proj.id, proj.name, proj.root_path,
        proj.description, proj.project_type, proj.language,
    )
    return db.get_project(proj.id)


@app.get("/api/projects/{project_id}")
def get_project(project_id: str):
    proj = db.get_project(project_id)
    if not proj:
        raise HTTPException(404, "Project not found")
    return proj


@app.put("/api/projects/{project_id}")
def update_project(project_id: str, update: ProjectUpdate):
    proj = db.get_project(project_id)
    if not proj:
        raise HTTPException(404, "Project not found")
    name = update.name if update.name is not None else proj["name"]
    description = update.description if update.description is not None else proj.get("description", "")
    language = update.language if update.language is not None else proj.get("language", "en")
    db.upsert_project(
        project_id, name, proj["root_path"],
        description, proj.get("project_type", ""), language,
    )
    return db.get_project(project_id)


@app.delete("/api/projects/{project_id}", status_code=204)
def delete_project(project_id: str):
    proj = db.get_project(project_id)
    if not proj:
        raise HTTPException(404, "Project not found")
    conn = db.get_connection()
    try:
        conn.execute("DELETE FROM projects WHERE id = ?", (project_id,))
        conn.commit()
    finally:
        conn.close()
    return None


@app.get("/api/projects/{project_id}/stats")
def project_stats(project_id: str):
    proj = db.get_project(project_id)
    if not proj:
        raise HTTPException(404, "Project not found")
    return db.get_project_stats(project_id)


@app.post("/api/projects/{project_id}/approve-all")
def approve_all(project_id: str):
    proj = db.get_project(project_id)
    if not proj:
        raise HTTPException(404, "Project not found")
    pending = db.get_pending_entries(project_id)
    entry_ids = [e["id"] for e in pending]
    if entry_ids:
        db.approve_entries(entry_ids)
    return {"ok": True, "approved": len(entry_ids)}


@app.post("/api/projects/{project_id}/reject-all")
def reject_all(project_id: str):
    proj = db.get_project(project_id)
    if not proj:
        raise HTTPException(404, "Project not found")
    pending = db.get_pending_entries(project_id)
    entry_ids = [e["id"] for e in pending]
    if entry_ids:
        db.reject_entries(entry_ids)
    return {"ok": True, "rejected": len(entry_ids)}


# ---- Server startup ----

_server_thread = None
_server_port = None


def start_api_server(port=8000):
    """Start uvicorn in a daemon thread. Returns the port."""
    global _server_thread, _server_port
    if _server_thread is not None:
        return _server_port
    config = uvicorn.Config(app, host="0.0.0.0", port=port, log_level="warning")
    server = uvicorn.Server(config)
    _server_thread = threading.Thread(target=server.run, daemon=True)
    _server_thread.start()
    _server_port = port
    return _server_port
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag && python3 -m pytest tests/test_api.py -v`
Expected: PASS — all project endpoint tests green

- [ ] **Step 8: Commit**

```bash
cd /Users/freis/Projects/PERSONAL/rag
git add rag/requirements.txt rag/server/api.py rag/tests/test_api.py rag/tests/conftest.py
git commit -m "feat: FastAPI REST API with project CRUD endpoints"
```

---

## Task 2: FastAPI API — Entry Endpoints

**Files:**
- Modify: `rag/server/api.py` (add entry endpoints)
- Modify: `rag/tests/test_api.py` (add entry tests)

- [ ] **Step 1: Write failing tests for entry endpoints**

Append to `rag/tests/test_api.py`:

```python
class TestEntries:
    def _setup_project(self, client, pid="test-proj"):
        client.post("/api/projects", json={
            "id": pid, "name": "Test", "root_path": "/tmp/test",
        })

    def test_list_entries_empty(self, client):
        self._setup_project(client)
        resp = client.get("/api/entries?project_id=test-proj")
        assert resp.status_code == 200
        assert resp.json() == []

    def test_create_entry(self, client):
        self._setup_project(client)
        resp = client.post("/api/entries", json={
            "project_id": "test-proj",
            "title": "Order rule",
            "content": "Orders over 1000 need approval",
            "category": "business-rule",
            "tags": ["orders", "approval"],
        })
        assert resp.status_code == 201
        data = resp.json()
        assert data["title"] == "Order rule"
        assert data["status"] == "pending"
        assert set(data["tags"]) == {"orders", "approval"}

    def test_get_entry(self, client):
        self._setup_project(client)
        create = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Rule", "content": "content",
        })
        eid = create.json()["id"]
        resp = client.get(f"/api/entries/{eid}")
        assert resp.status_code == 200
        assert resp.json()["title"] == "Rule"

    def test_get_entry_not_found(self, client):
        resp = client.get("/api/entries/nonexistent-id")
        assert resp.status_code == 404

    def test_update_entry(self, client):
        self._setup_project(client)
        create = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Rule", "content": "content",
        })
        eid = create.json()["id"]
        resp = client.put(f"/api/entries/{eid}", json={
            "title": "Updated Rule",
            "content": "new content",
            "category": "architecture",
            "tags": ["auth"],
        })
        assert resp.status_code == 200
        assert resp.json()["title"] == "Updated Rule"
        assert resp.json()["category"] == "architecture"
        assert resp.json()["tags"] == ["auth"]

    def test_delete_entry(self, client):
        self._setup_project(client)
        create = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Rule", "content": "content",
        })
        eid = create.json()["id"]
        resp = client.delete(f"/api/entries/{eid}")
        assert resp.status_code == 204
        assert client.get(f"/api/entries/{eid}").status_code == 404

    def test_approve_entry(self, client):
        self._setup_project(client)
        create = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Rule", "content": "content",
        })
        eid = create.json()["id"]
        resp = client.post(f"/api/entries/{eid}/approve")
        assert resp.status_code == 200
        entry = client.get(f"/api/entries/{eid}").json()
        assert entry["status"] == "indexed"

    def test_reject_entry(self, client):
        self._setup_project(client)
        create = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Rule", "content": "content",
        })
        eid = create.json()["id"]
        resp = client.post(f"/api/entries/{eid}/reject")
        assert resp.status_code == 200
        entry = client.get(f"/api/entries/{eid}").json()
        assert entry["status"] == "rejected"

    def test_list_entries_filter_by_status(self, client):
        self._setup_project(client)
        e1 = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "R1", "content": "c1",
        }).json()["id"]
        client.post("/api/entries", json={
            "project_id": "test-proj", "title": "R2", "content": "c2",
        })
        client.post(f"/api/entries/{e1}/approve")
        pending = client.get("/api/entries?project_id=test-proj&status=pending").json()
        indexed = client.get("/api/entries?project_id=test-proj&status=indexed").json()
        assert len(pending) == 1
        assert len(indexed) == 1

    def test_list_entries_filter_by_category(self, client):
        self._setup_project(client)
        client.post("/api/entries", json={
            "project_id": "test-proj", "title": "R1", "content": "c1",
            "category": "business-rule",
        })
        client.post("/api/entries", json={
            "project_id": "test-proj", "title": "R2", "content": "c2",
            "category": "architecture",
        })
        results = client.get("/api/entries?project_id=test-proj&category=business-rule").json()
        assert len(results) == 1
        assert results[0]["title"] == "R1"
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag && python3 -m pytest tests/test_api.py::TestEntries -v`
Expected: FAIL — entry endpoints not defined

- [ ] **Step 3: Add entry endpoints to api.py**

Add the following to `rag/server/api.py` before the `# ---- Server startup ----` section:

```python
# ---- Entry endpoints ----

@app.get("/api/entries")
def list_entries(
    project_id: str = Query(...),
    category: str | None = None,
    tags: list[str] | None = Query(None),
    status: str | None = None,
    limit: int = 500,
):
    return db.list_entries(project_id, category=category, tags=tags, status=status, limit=limit)


@app.post("/api/entries", status_code=201)
def create_entry(entry: EntryCreate):
    entry_id = db.store_knowledge_entry(
        project_id=entry.project_id,
        title=entry.title,
        content=entry.content,
        category=entry.category,
        source="manual",
        tags=entry.tags,
    )
    return db.get_entry(entry_id)


@app.get("/api/entries/{entry_id}")
def get_entry(entry_id: str):
    entry = db.get_entry(entry_id)
    if not entry:
        raise HTTPException(404, "Entry not found")
    return entry


@app.put("/api/entries/{entry_id}")
def update_entry(entry_id: str, update: EntryUpdate):
    entry = db.get_entry(entry_id)
    if not entry:
        raise HTTPException(404, "Entry not found")
    db.update_entry(
        entry_id,
        title=update.title,
        content=update.content,
        category=update.category,
        tags=update.tags,
    )
    return db.get_entry(entry_id)


@app.delete("/api/entries/{entry_id}", status_code=204)
def delete_entry(entry_id: str):
    entry = db.get_entry(entry_id)
    if not entry:
        raise HTTPException(404, "Entry not found")
    db.remove_entry(entry_id)
    return None


@app.post("/api/entries/{entry_id}/approve")
def approve_entry(entry_id: str):
    entry = db.get_entry(entry_id)
    if not entry:
        raise HTTPException(404, "Entry not found")
    db.approve_entries([entry_id])
    return {"ok": True}


@app.post("/api/entries/{entry_id}/reject")
def reject_entry(entry_id: str):
    entry = db.get_entry(entry_id)
    if not entry:
        raise HTTPException(404, "Entry not found")
    db.reject_entries([entry_id])
    return {"ok": True}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag && python3 -m pytest tests/test_api.py -v`
Expected: PASS — all entry endpoint tests green

- [ ] **Step 5: Commit**

```bash
cd /Users/freis/Projects/PERSONAL/rag
git add rag/server/api.py rag/tests/test_api.py
git commit -m "feat: FastAPI entry CRUD and approve/reject endpoints"
```

---

## Task 3: FastAPI API — Search and Tags Endpoints

**Files:**
- Modify: `rag/server/api.py` (add search + tags endpoints)
- Modify: `rag/tests/test_api.py` (add search + tags tests)

- [ ] **Step 1: Write failing tests for search and tags**

Append to `rag/tests/test_api.py`:

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


class TestTags:
    def test_list_tags(self, client):
        client.post("/api/projects", json={
            "id": "test-proj", "name": "Test", "root_path": "/tmp/test",
        })
        client.post("/api/entries", json={
            "project_id": "test-proj", "title": "R1", "content": "c1",
            "tags": ["auth", "security"],
        })
        resp = client.get("/api/tags?project_id=test-proj")
        assert resp.status_code == 200
        tags = resp.json()
        assert "auth" in tags
        assert "security" in tags

    def test_list_tags_empty(self, client):
        client.post("/api/projects", json={
            "id": "test-proj", "name": "Test", "root_path": "/tmp/test",
        })
        resp = client.get("/api/tags?project_id=test-proj")
        assert resp.status_code == 200
        assert resp.json() == []
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag && python3 -m pytest tests/test_api.py::TestSearch tests/test_api.py::TestTags -v`
Expected: FAIL — search/tags endpoints not defined

- [ ] **Step 3: Add search and tags endpoints to api.py**

Add the following to `rag/server/api.py` before the `# ---- Server startup ----` section:

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
    import search_engine
    entries = db.get_indexed_entries(project_id)
    if not entries:
        return []
    index = search_engine.build_index_from_entries(entries)
    return index.search(q, top_k=top_k, category=category, tags=tags)


@app.get("/api/tags")
def list_tags(project_id: str = Query(...)):
    return db.get_all_tags(project_id)
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag && python3 -m pytest tests/test_api.py -v`
Expected: PASS — all API tests green

- [ ] **Step 5: Commit**

```bash
cd /Users/freis/Projects/PERSONAL/rag
git add rag/server/api.py rag/tests/test_api.py
git commit -m "feat: FastAPI search and tags endpoints"
```

---

## Task 4: Wire API into main.py and Delete web_ui

**Files:**
- Modify: `rag/server/main.py` (replace web_ui with api)
- Delete: `rag/server/web_ui.py`
- Delete: `rag/server/templates/approval.html`

- [ ] **Step 1: Update main.py to use api instead of web_ui**

In `rag/server/main.py`, make three changes:

1. Replace `import web_ui` with `import api` (line 19):

```python
import api
```

2. Replace the web_ui startup in `main()` (lines 595-599):

```python
    try:
        api.start_api_server(8000)
        log("Admin panel API at http://127.0.0.1:8000")
        log("Admin panel UI at http://127.0.0.1:8765")
    except Exception as e:
        log(f"Could not start API server: {e}")
```

3. Update the `_open_approval_ui` handler to return the new URL. Find the `_open_approval_ui` function and replace its return:

```python
def _open_approval_ui(args):
    port = args.get("port", 8765)
    url = f"http://127.0.0.1:{port}"

    try:
        import webbrowser
        webbrowser.open(url)
    except Exception:
        pass

    return {"content": [{"type": "text", "text": f"Admin panel at {url}"}]}
```

- [ ] **Step 2: Delete web_ui.py and approval.html**

```bash
rm /Users/freis/Projects/PERSONAL/rag/rag/server/web_ui.py
rm /Users/freis/Projects/PERSONAL/rag/rag/server/templates/approval.html
rmdir /Users/freis/Projects/PERSONAL/rag/rag/server/templates 2>/dev/null || true
```

- [ ] **Step 3: Verify MCP server still starts**

Run: `echo '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' | python3 /Users/freis/Projects/PERSONAL/rag/rag/server/main.py 2>/dev/null | head -1`
Expected: JSON response with `"name":"rag"`

- [ ] **Step 4: Run all existing tests**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag && python3 -m pytest tests/ -v`
Expected: PASS — all tests green (db, search, import, integration, api)

- [ ] **Step 5: Commit**

```bash
cd /Users/freis/Projects/PERSONAL/rag
git add rag/server/main.py
git rm rag/server/web_ui.py rag/server/templates/approval.html
git commit -m "refactor: replace web_ui with FastAPI api in main.py"
```

---

## Task 5: React Project Scaffold

**Files:**
- Create: `rag/web/package.json`
- Create: `rag/web/vite.config.ts`
- Create: `rag/web/tsconfig.json`
- Create: `rag/web/tailwind.config.ts`
- Create: `rag/web/postcss.config.js`
- Create: `rag/web/components.json`
- Create: `rag/web/index.html`
- Create: `rag/web/src/main.tsx`
- Create: `rag/web/src/App.tsx`
- Create: `rag/web/src/index.css`
- Create: `rag/web/src/lib/utils.ts`
- Create: `rag/web/src/lib/api.ts`

- [ ] **Step 1: Create package.json**

Create `rag/web/package.json`:

```json
{
  "name": "rag-admin-panel",
  "private": true,
  "version": "0.4.0",
  "type": "module",
  "scripts": {
    "dev": "vite",
    "build": "tsc -b && vite build",
    "preview": "vite preview"
  },
  "dependencies": {
    "react": "^18.3.1",
    "react-dom": "^18.3.1",
    "react-router-dom": "^6.26.0",
    "class-variance-authority": "^0.7.0",
    "clsx": "^2.1.1",
    "tailwind-merge": "^2.5.0",
    "lucide-react": "^0.400.0"
  },
  "devDependencies": {
    "@types/react": "^18.3.3",
    "@types/react-dom": "^18.3.0",
    "@vitejs/plugin-react": "^4.3.1",
    "typescript": "^5.5.3",
    "vite": "^5.4.0",
    "tailwindcss": "^3.4.0",
    "postcss": "^8.4.40",
    "autoprefixer": "^10.4.19"
  }
}
```

- [ ] **Step 2: Create vite.config.ts**

Create `rag/web/vite.config.ts`:

```typescript
import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import path from "path";

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      "@": path.resolve(__dirname, "./src"),
    },
  },
  server: {
    proxy: {
      "/api": "http://localhost:8000",
    },
  },
});
```

- [ ] **Step 3: Create tsconfig.json**

Create `rag/web/tsconfig.json`:

```json
{
  "compilerOptions": {
    "target": "ES2020",
    "useDefineForClassFields": true,
    "lib": ["ES2020", "DOM", "DOM.Iterable"],
    "module": "ESNext",
    "skipLibCheck": true,
    "moduleResolution": "bundler",
    "allowImportingTsExtensions": true,
    "resolveJsonModule": true,
    "isolatedModules": true,
    "noEmit": true,
    "jsx": "react-jsx",
    "strict": true,
    "baseUrl": ".",
    "paths": {
      "@/*": ["./src/*"]
    }
  },
  "include": ["src"]
}
```

- [ ] **Step 4: Create tailwind.config.ts**

Create `rag/web/tailwind.config.ts`:

```typescript
import type { Config } from "tailwindcss";

const config: Config = {
  darkMode: ["class"],
  content: ["./index.html", "./src/**/*.{ts,tsx}"],
  theme: {
    extend: {
      colors: {
        border: "hsl(var(--border))",
        input: "hsl(var(--input))",
        ring: "hsl(var(--ring))",
        background: "hsl(var(--background))",
        foreground: "hsl(var(--foreground))",
        primary: {
          DEFAULT: "hsl(var(--primary))",
          foreground: "hsl(var(--primary-foreground))",
        },
        secondary: {
          DEFAULT: "hsl(var(--secondary))",
          foreground: "hsl(var(--secondary-foreground))",
        },
        destructive: {
          DEFAULT: "hsl(var(--destructive))",
          foreground: "hsl(var(--destructive-foreground))",
        },
        muted: {
          DEFAULT: "hsl(var(--muted))",
          foreground: "hsl(var(--muted-foreground))",
        },
        accent: {
          DEFAULT: "hsl(var(--accent))",
          foreground: "hsl(var(--accent-foreground))",
        },
        card: {
          DEFAULT: "hsl(var(--card))",
          foreground: "hsl(var(--card-foreground))",
        },
      },
      borderRadius: {
        lg: "var(--radius)",
        md: "calc(var(--radius) - 2px)",
        sm: "calc(var(--radius) - 4px)",
      },
    },
  },
  plugins: [],
};

export default config;
```

- [ ] **Step 5: Create postcss.config.js**

Create `rag/web/postcss.config.js`:

```javascript
export default {
  plugins: {
    tailwindcss: {},
    autoprefixer: {},
  },
};
```

- [ ] **Step 6: Create components.json (shadcn/ui config)**

Create `rag/web/components.json`:

```json
{
  "$schema": "https://ui.shadcn.com/schema.json",
  "style": "default",
  "rsc": false,
  "tsx": true,
  "tailwind": {
    "config": "tailwind.config.ts",
    "css": "src/index.css",
    "baseColor": "slate",
    "cssVariables": true
  },
  "aliases": {
    "components": "@/components",
    "utils": "@/lib/utils"
  }
}
```

- [ ] **Step 7: Create index.html**

Create `rag/web/index.html`:

```html
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>RAG Admin Panel</title>
</head>
<body>
  <div id="root"></div>
  <script type="module" src="/src/main.tsx"></script>
</body>
</html>
```

- [ ] **Step 8: Create src/index.css**

Create `rag/web/src/index.css`:

```css
@tailwind base;
@tailwind components;
@tailwind utilities;

@layer base {
  :root {
    --background: 0 0% 100%;
    --foreground: 222.2 84% 4.9%;
    --card: 0 0% 100%;
    --card-foreground: 222.2 84% 4.9%;
    --primary: 222.2 47.4% 11.2%;
    --primary-foreground: 210 40% 98%;
    --secondary: 210 40% 96.1%;
    --secondary-foreground: 222.2 47.4% 11.2%;
    --muted: 210 40% 96.1%;
    --muted-foreground: 215.4 16.3% 46.9%;
    --accent: 210 40% 96.1%;
    --accent-foreground: 222.2 47.4% 11.2%;
    --destructive: 0 84.2% 60.2%;
    --destructive-foreground: 210 40% 98%;
    --border: 214.3 31.8% 91.4%;
    --input: 214.3 31.8% 91.4%;
    --ring: 222.2 84% 4.9%;
    --radius: 0.5rem;
  }

  .dark {
    --background: 222.2 84% 4.9%;
    --foreground: 210 40% 98%;
    --card: 222.2 84% 4.9%;
    --card-foreground: 210 40% 98%;
    --primary: 210 40% 98%;
    --primary-foreground: 222.2 47.4% 11.2%;
    --secondary: 217.2 32.6% 17.5%;
    --secondary-foreground: 210 40% 98%;
    --muted: 217.2 32.6% 17.5%;
    --muted-foreground: 215 20.2% 65.1%;
    --accent: 217.2 32.6% 17.5%;
    --accent-foreground: 210 40% 98%;
    --destructive: 0 62.8% 30.6%;
    --destructive-foreground: 210 40% 98%;
    --border: 217.2 32.6% 17.5%;
    --input: 217.2 32.6% 17.5%;
    --ring: 212.7 26.8% 83.9%;
  }
}

@layer base {
  * {
    @apply border-border;
  }
  body {
    @apply bg-background text-foreground;
  }
}
```

- [ ] **Step 9: Create src/lib/utils.ts**

Create `rag/web/src/lib/utils.ts`:

```typescript
import { type ClassValue, clsx } from "clsx";
import { twMerge } from "tailwind-merge";

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}
```

- [ ] **Step 10: Create src/lib/api.ts**

Create `rag/web/src/lib/api.ts`:

```typescript
const API_BASE = "/api";

export interface Project {
  id: string;
  name: string;
  root_path: string;
  description: string;
  project_type: string;
  language: string;
  created_at: number;
  updated_at: number;
  indexed_count?: number;
  pending_count?: number;
}

export interface Entry {
  id: string;
  project_id: string;
  title: string;
  content: string;
  category: string;
  source: string;
  author: string;
  status: string;
  tags: string[];
  metadata: Record<string, unknown>;
  created_at: number;
  updated_at: number;
}

export interface SearchResult {
  id: string;
  title: string;
  content: string;
  category: string;
  tags: string[];
  score: number;
}

export interface ProjectCreate {
  id: string;
  name: string;
  root_path: string;
  description?: string;
  language?: string;
}

export interface EntryCreate {
  project_id: string;
  title: string;
  content: string;
  category?: string;
  tags?: string[];
}

export interface EntryUpdate {
  title?: string;
  content?: string;
  category?: string;
  tags?: string[];
}

async function fetchJSON<T>(url: string, options?: RequestInit): Promise<T> {
  const resp = await fetch(`${API_BASE}${url}`, {
    headers: { "Content-Type": "application/json" },
    ...options,
  });
  if (!resp.ok) {
    const text = await resp.text();
    throw new Error(text || resp.statusText);
  }
  if (resp.status === 204) return undefined as T;
  return resp.json();
}

export const api = {
  listProjects: () => fetchJSON<Project[]>("/projects"),
  getProject: (id: string) => fetchJSON<Project>(`/projects/${id}`),
  createProject: (data: ProjectCreate) =>
    fetchJSON<Project>("/projects", { method: "POST", body: JSON.stringify(data) }),
  updateProject: (id: string, data: Partial<ProjectCreate>) =>
    fetchJSON<Project>(`/projects/${id}`, { method: "PUT", body: JSON.stringify(data) }),
  deleteProject: (id: string) =>
    fetchJSON<void>(`/projects/${id}`, { method: "DELETE" }),
  projectStats: (id: string) =>
    fetchJSON<{ indexed: number; pending: number; rejected: number; total: number }>(`/projects/${id}/stats`),
  approveAll: (id: string) =>
    fetchJSON<{ ok: boolean }>(`/projects/${id}/approve-all`, { method: "POST" }),
  rejectAll: (id: string) =>
    fetchJSON<{ ok: boolean }>(`/projects/${id}/reject-all`, { method: "POST" }),

  listEntries: (params: { project_id: string; category?: string; status?: string; tags?: string[] }) => {
    const search = new URLSearchParams({ project_id: params.project_id });
    if (params.category) search.set("category", params.category);
    if (params.status) search.set("status", params.status);
    if (params.tags) params.tags.forEach((t) => search.append("tags", t));
    return fetchJSON<Entry[]>(`/entries?${search}`);
  },
  getEntry: (id: string) => fetchJSON<Entry>(`/entries/${id}`),
  createEntry: (data: EntryCreate) =>
    fetchJSON<Entry>("/entries", { method: "POST", body: JSON.stringify(data) }),
  updateEntry: (id: string, data: EntryUpdate) =>
    fetchJSON<Entry>(`/entries/${id}`, { method: "PUT", body: JSON.stringify(data) }),
  deleteEntry: (id: string) => fetchJSON<void>(`/entries/${id}`, { method: "DELETE" }),
  approveEntry: (id: string) =>
    fetchJSON<{ ok: boolean }>(`/entries/${id}/approve`, { method: "POST" }),
  rejectEntry: (id: string) =>
    fetchJSON<{ ok: boolean }>(`/entries/${id}/reject`, { method: "POST" }),

  search: (params: { q: string; project_id: string; category?: string; tags?: string[]; top_k?: number }) => {
    const search = new URLSearchParams({ q: params.q, project_id: params.project_id });
    if (params.category) search.set("category", params.category);
    if (params.tags) params.tags.forEach((t) => search.append("tags", t));
    if (params.top_k) search.set("top_k", String(params.top_k));
    return fetchJSON<SearchResult[]>(`/search?${search}`);
  },
  listTags: (projectId: string) =>
    fetchJSON<string[]>(`/tags?project_id=${projectId}`),
};
```

- [ ] **Step 11: Create src/main.tsx**

Create `rag/web/src/main.tsx`:

```typescript
import React from "react";
import ReactDOM from "react-dom/client";
import { BrowserRouter } from "react-router-dom";
import App from "./App";
import "./index.css";

ReactDOM.createRoot(document.getElementById("root")!).render(
  <React.StrictMode>
    <BrowserRouter>
      <App />
    </BrowserRouter>
  </React.StrictMode>
);
```

- [ ] **Step 12: Create src/App.tsx (placeholder)**

Create `rag/web/src/App.tsx`:

```typescript
import { Routes, Route } from "react-router-dom";

function Placeholder({ name }: { name: string }) {
  return <div className="p-8 text-muted-foreground">{name} — coming soon</div>;
}

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<Placeholder name="Dashboard" />} />
      <Route path="/projects" element={<Placeholder name="Projects" />} />
      <Route path="/entries" element={<Placeholder name="Entries" />} />
      <Route path="/entries/new" element={<Placeholder name="New Entry" />} />
      <Route path="/entries/:id" element={<Placeholder name="Entry Detail" />} />
      <Route path="/approvals" element={<Placeholder name="Approvals" />} />
      <Route path="/search" element={<Placeholder name="Search" />} />
    </Routes>
  );
}
```

- [ ] **Step 13: Install dependencies and verify build**

Run:
```bash
cd /Users/freis/Projects/PERSONAL/rag/rag/web && npm install && npm run build
```
Expected: Build succeeds, `dist/` folder created

- [ ] **Step 14: Commit**

```bash
cd /Users/freis/Projects/PERSONAL/rag
git add rag/web/
git commit -m "feat: React + Vite + shadcn/ui scaffold with API client"
```

---

## Task 6: shadcn/ui Base Components

**Files:**
- Create: `rag/web/src/components/ui/button.tsx`
- Create: `rag/web/src/components/ui/input.tsx`
- Create: `rag/web/src/components/ui/textarea.tsx`
- Create: `rag/web/src/components/ui/label.tsx`
- Create: `rag/web/src/components/ui/badge.tsx`
- Create: `rag/web/src/components/ui/card.tsx`
- Create: `rag/web/src/components/ui/dialog.tsx`
- Create: `rag/web/src/components/ui/select.tsx`
- Create: `rag/web/src/components/ui/table.tsx`
- Create: `rag/web/src/components/ui/tabs.tsx`
- Create: `rag/web/src/components/ui/sonner.tsx` (toast)

- [ ] **Step 1: Create button.tsx**

Create `rag/web/src/components/ui/button.tsx`:

```typescript
import * as React from "react";
import { cva, type VariantProps } from "class-variance-authority";
import { cn } from "@/lib/utils";

const buttonVariants = cva(
  "inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50",
  {
    variants: {
      variant: {
        default: "bg-primary text-primary-foreground hover:bg-primary/90",
        destructive: "bg-destructive text-destructive-foreground hover:bg-destructive/90",
        outline: "border border-input bg-background hover:bg-accent hover:text-accent-foreground",
        secondary: "bg-secondary text-secondary-foreground hover:bg-secondary/80",
        ghost: "hover:bg-accent hover:text-accent-foreground",
        link: "text-primary underline-offset-4 hover:underline",
      },
      size: {
        default: "h-10 px-4 py-2",
        sm: "h-9 rounded-md px-3",
        lg: "h-11 rounded-md px-8",
        icon: "h-10 w-10",
      },
    },
    defaultVariants: { variant: "default", size: "default" },
  }
);

export interface ButtonProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement>,
    VariantProps<typeof buttonVariants> {}

export const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(
  ({ className, variant, size, ...props }, ref) => (
    <button className={cn(buttonVariants({ variant, size, className }))} ref={ref} {...props} />
  )
);
Button.displayName = "Button";

export { buttonVariants };
```

- [ ] **Step 2: Create input.tsx**

Create `rag/web/src/components/ui/input.tsx`:

```typescript
import * as React from "react";
import { cn } from "@/lib/utils";

export const Input = React.forwardRef<HTMLInputElement, React.InputHTMLAttributes<HTMLInputElement>>(
  ({ className, ...props }, ref) => (
    <input
      className={cn(
        "flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50",
        className
      )}
      ref={ref}
      {...props}
    />
  )
);
Input.displayName = "Input";
```

- [ ] **Step 3: Create textarea.tsx**

Create `rag/web/src/components/ui/textarea.tsx`:

```typescript
import * as React from "react";
import { cn } from "@/lib/utils";

export const Textarea = React.forwardRef<HTMLTextAreaElement, React.TextareaHTMLAttributes<HTMLTextAreaElement>>(
  ({ className, ...props }, ref) => (
    <textarea
      className={cn(
        "flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50",
        className
      )}
      ref={ref}
      {...props}
    />
  )
);
Textarea.displayName = "Textarea";
```

- [ ] **Step 4: Create label.tsx**

Create `rag/web/src/components/ui/label.tsx`:

```typescript
import * as React from "react";
import { cn } from "@/lib/utils";

export const Label = React.forwardRef<HTMLLabelElement, React.LabelHTMLAttributes<HTMLLabelElement>>(
  ({ className, ...props }, ref) => (
    <label
      className={cn("text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70", className)}
      ref={ref}
      {...props}
    />
  )
);
Label.displayName = "Label";
```

- [ ] **Step 5: Create badge.tsx**

Create `rag/web/src/components/ui/badge.tsx`:

```typescript
import { cva, type VariantProps } from "class-variance-authority";
import { cn } from "@/lib/utils";

const badgeVariants = cva(
  "inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold transition-colors focus:outline-none",
  {
    variants: {
      variant: {
        default: "border-transparent bg-primary text-primary-foreground",
        secondary: "border-transparent bg-secondary text-secondary-foreground",
        destructive: "border-transparent bg-destructive text-destructive-foreground",
        outline: "text-foreground",
      },
    },
    defaultVariants: { variant: "default" },
  }
);

export interface BadgeProps
  extends React.HTMLAttributes<HTMLDivElement>,
    VariantProps<typeof badgeVariants> {}

export function Badge({ className, variant, ...props }: BadgeProps) {
  return <div className={cn(badgeVariants({ variant }), className)} {...props} />;
}
```

- [ ] **Step 6: Create card.tsx**

Create `rag/web/src/components/ui/card.tsx`:

```typescript
import * as React from "react";
import { cn } from "@/lib/utils";

export const Card = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => (
    <div className={cn("rounded-lg border bg-card text-card-foreground shadow-sm", className)} ref={ref} {...props} />
  )
);
Card.displayName = "Card";

export const CardHeader = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => (
    <div className={cn("flex flex-col space-y-1.5 p-6", className)} ref={ref} {...props} />
  )
);
CardHeader.displayName = "CardHeader";

export const CardTitle = React.forwardRef<HTMLParagraphElement, React.HTMLAttributes<HTMLHeadingElement>>(
  ({ className, ...props }, ref) => (
    <h3 className={cn("text-2xl font-semibold leading-none tracking-tight", className)} ref={ref} {...props} />
  )
);
CardTitle.displayName = "CardTitle";

export const CardContent = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => (
    <div className={cn("p-6 pt-0", className)} ref={ref} {...props} />
  )
);
CardContent.displayName = "CardContent";
```

- [ ] **Step 7: Create dialog.tsx**

Create `rag/web/src/components/ui/dialog.tsx`:

```typescript
import * as React from "react";
import { cn } from "@/lib/utils";

export function Dialog({ open, onOpenChange, children }: { open: boolean; onOpenChange: (open: boolean) => void; children: React.ReactNode }) {
  if (!open) return null;
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center">
      <div className="fixed inset-0 bg-black/80" onClick={() => onOpenChange(false)} />
      <div className="relative z-50 w-full max-w-lg rounded-lg border bg-background p-6 shadow-lg">
        {children}
      </div>
    </div>
  );
}

export function DialogHeader({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
  return <div className={cn("flex flex-col space-y-1.5 text-center sm:text-left mb-4", className)} {...props} />;
}

export function DialogTitle({ className, ...props }: React.HTMLAttributes<HTMLHeadingElement>) {
  return <h2 className={cn("text-lg font-semibold leading-none tracking-tight", className)} {...props} />;
}

export function DialogFooter({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
  return <div className={cn("flex flex-col-reverse sm:flex-row sm:justify-end sm:space-x-2 mt-4", className)} {...props} />;
}
```

- [ ] **Step 8: Create select.tsx**

Create `rag/web/src/components/ui/select.tsx`:

```typescript
import * as React from "react";
import { cn } from "@/lib/utils";

export const Select = React.forwardRef<HTMLSelectElement, React.SelectHTMLAttributes<HTMLSelectElement>>(
  ({ className, children, ...props }, ref) => (
    <select
      className={cn(
        "flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50",
        className
      )}
      ref={ref}
      {...props}
    >
      {children}
    </select>
  )
);
Select.displayName = "Select";
```

- [ ] **Step 9: Create table.tsx**

Create `rag/web/src/components/ui/table.tsx`:

```typescript
import * as React from "react";
import { cn } from "@/lib/utils";

export const Table = React.forwardRef<HTMLTableElement, React.HTMLAttributes<HTMLTableElement>>(
  ({ className, ...props }, ref) => (
    <div className="relative w-full overflow-auto">
      <table className={cn("w-full caption-bottom text-sm", className)} ref={ref} {...props} />
    </div>
  )
);
Table.displayName = "Table";

export const TableHeader = React.forwardRef<HTMLTableSectionElement, React.HTMLAttributes<HTMLTableSectionElement>>(
  ({ className, ...props }, ref) => <thead className={cn("[&_tr]:border-b", className)} ref={ref} {...props} />
);
TableHeader.displayName = "TableHeader";

export const TableBody = React.forwardRef<HTMLTableSectionElement, React.HTMLAttributes<HTMLTableSectionElement>>(
  ({ className, ...props }, ref) => <tbody className={cn("[&_tr:last-child]:border-0", className)} ref={ref} {...props} />
);
TableBody.displayName = "TableBody";

export const TableRow = React.forwardRef<HTMLTableRowElement, React.HTMLAttributes<HTMLTableRowElement>>(
  ({ className, ...props }, ref) => (
    <tr className={cn("border-b transition-colors hover:bg-muted/50 data-[state=selected]:bg-muted", className)} ref={ref} {...props} />
  )
);
TableRow.displayName = "TableRow";

export const TableHead = React.forwardRef<HTMLTableCellElement, React.ThHTMLAttributes<HTMLTableCellElement>>(
  ({ className, ...props }, ref) => (
    <th className={cn("h-12 px-4 text-left align-middle font-medium text-muted-foreground", className)} ref={ref} {...props} />
  )
);
TableHead.displayName = "TableHead";

export const TableCell = React.forwardRef<HTMLTableCellElement, React.TdHTMLAttributes<HTMLTableCellElement>>(
  ({ className, ...props }, ref) => (
    <td className={cn("p-4 align-middle", className)} ref={ref} {...props} />
  )
);
TableCell.displayName = "TableCell";
```

- [ ] **Step 10: Create tabs.tsx**

Create `rag/web/src/components/ui/tabs.tsx`:

```typescript
import * as React from "react";
import { cn } from "@/lib/utils";

interface TabsContextValue {
  value: string;
  onChange: (value: string) => void;
}

const TabsContext = React.createContext<TabsContextValue | null>(null);

export function Tabs({ value, onValueChange, children }: { value: string; onValueChange: (v: string) => void; children: React.ReactNode }) {
  return <TabsContext.Provider value={{ value, onChange: onValueChange }}>{children}</TabsContext.Provider>;
}

export function TabsList({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
  return <div className={cn("inline-flex h-10 items-center justify-center rounded-md bg-muted p-1 text-muted-foreground", className)} {...props} />;
}

export function TabsTrigger({ value, className, ...props }: React.ButtonHTMLAttributes<HTMLButtonElement> & { value: string }) {
  const ctx = React.useContext(TabsContext);
  if (!ctx) throw new Error("TabsTrigger must be used within Tabs");
  return (
    <button
      className={cn(
        "inline-flex items-center justify-center whitespace-nowrap rounded-sm px-3 py-1.5 text-sm font-medium ring-offset-background transition-all focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50",
        ctx.value === value ? "bg-background text-foreground shadow-sm" : "",
        className
      )}
      onClick={() => ctx.onChange(value)}
      {...props}
    />
  );
}
```

- [ ] **Step 11: Verify build**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag/web && npm run build`
Expected: Build succeeds

- [ ] **Step 12: Commit**

```bash
cd /Users/freis/Projects/PERSONAL/rag
git add rag/web/src/components/ui/
git commit -m "feat: shadcn/ui base components (button, input, card, dialog, table, etc.)"
```

---

## Task 7: Layout and Shared Components

**Files:**
- Create: `rag/web/src/components/Layout.tsx`
- Create: `rag/web/src/components/EntryForm.tsx`
- Modify: `rag/web/src/App.tsx` (wire up Layout + routes)

- [ ] **Step 1: Create Layout.tsx**

Create `rag/web/src/components/Layout.tsx`:

```typescript
import { NavLink, Outlet } from "react-router-dom";
import { cn } from "@/lib/utils";
import { LayoutDashboard, FolderKanban, FileText, CheckCircle, Search } from "lucide-react";

const navItems = [
  { to: "/", label: "Dashboard", icon: LayoutDashboard },
  { to: "/projects", label: "Projects", icon: FolderKanban },
  { to: "/entries", label: "Entries", icon: FileText },
  { to: "/approvals", label: "Approvals", icon: CheckCircle },
  { to: "/search", label: "Search", icon: Search },
];

export default function Layout() {
  return (
    <div className="flex min-h-screen bg-background">
      <aside className="w-60 border-r bg-card flex flex-col">
        <div className="p-6 border-b">
          <h1 className="text-lg font-bold">RAG Admin</h1>
          <p className="text-xs text-muted-foreground">Knowledge Base</p>
        </div>
        <nav className="flex-1 p-3 space-y-1">
          {navItems.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.to === "/"}
              className={({ isActive }) =>
                cn(
                  "flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors",
                  isActive
                    ? "bg-secondary text-secondary-foreground"
                    : "text-muted-foreground hover:bg-accent hover:text-accent-foreground"
                )
              }
            >
              <item.icon className="h-4 w-4" />
              {item.label}
            </NavLink>
          ))}
        </nav>
      </aside>
      <main className="flex-1 overflow-auto">
        <Outlet />
      </main>
    </div>
  );
}
```

- [ ] **Step 2: Create EntryForm.tsx**

Create `rag/web/src/components/EntryForm.tsx`:

```typescript
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { api, type Project, type EntryCreate, type EntryUpdate } from "@/lib/api";

const CATEGORIES = [
  "business-rule", "design-decision", "architecture",
  "documentation", "insight", "convention", "constraint",
];

interface EntryFormProps {
  projects: Project[];
  entry?: { id: string; title: string; content: string; category: string; tags: string[]; project_id: string };
  onSubmit: () => void;
  onCancel: () => void;
}

export default function EntryForm({ projects, entry, onSubmit, onCancel }: EntryFormProps) {
  const [title, setTitle] = useState(entry?.title ?? "");
  const [content, setContent] = useState(entry?.content ?? "");
  const [category, setCategory] = useState(entry?.category ?? "insight");
  const [tags, setTags] = useState((entry?.tags ?? []).join(", "));
  const [projectId, setProjectId] = useState(entry?.project_id ?? projects[0]?.id ?? "");
  const [error, setError] = useState("");

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError("");
    const tagList = tags.split(",").map((t) => t.trim().toLowerCase()).filter(Boolean);
    try {
      if (entry) {
        const update: EntryUpdate = { title, content, category, tags: tagList };
        await api.updateEntry(entry.id, update);
      } else {
        const data: EntryCreate = { project_id: projectId, title, content, category, tags: tagList };
        await api.createEntry(data);
      }
      onSubmit();
    } catch (err) {
      setError(String(err.message || err));
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4 max-w-2xl">
      {!entry && (
        <div className="space-y-2">
          <Label htmlFor="project">Project</Label>
          <Select id="project" value={projectId} onChange={(e) => setProjectId(e.target.value)} required>
            {projects.map((p) => (
              <option key={p.id} value={p.id}>{p.name}</option>
            ))}
          </Select>
        </div>
      )}
      <div className="space-y-2">
        <Label htmlFor="title">Title</Label>
        <Input id="title" value={title} onChange={(e) => setTitle(e.target.value)} required />
      </div>
      <div className="space-y-2">
        <Label htmlFor="content">Content</Label>
        <Textarea id="content" value={content} onChange={(e) => setContent(e.target.value)} rows={8} required />
      </div>
      <div className="space-y-2">
        <Label htmlFor="category">Category</Label>
        <Select id="category" value={category} onChange={(e) => setCategory(e.target.value)}>
          {CATEGORIES.map((c) => (
            <option key={c} value={c}>{c}</option>
          ))}
        </Select>
      </div>
      <div className="space-y-2">
        <Label htmlFor="tags">Tags (comma-separated)</Label>
        <Input id="tags" value={tags} onChange={(e) => setTags(e.target.value)} placeholder="auth, security, payments" />
      </div>
      {error && <p className="text-sm text-destructive">{error}</p>}
      <div className="flex gap-2">
        <Button type="submit">{entry ? "Update" : "Create"}</Button>
        <Button type="button" variant="outline" onClick={onCancel}>Cancel</Button>
      </div>
    </form>
  );
}
```

- [ ] **Step 3: Update App.tsx with Layout and routes**

Replace `rag/web/src/App.tsx`:

```typescript
import { Routes, Route } from "react-router-dom";
import Layout from "@/components/Layout";

function Placeholder({ name }: { name: string }) {
  return <div className="p-8 text-muted-foreground">{name} — coming soon</div>;
}

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<Layout />}>
        <Route index element={<Placeholder name="Dashboard" />} />
        <Route path="projects" element={<Placeholder name="Projects" />} />
        <Route path="entries" element={<Placeholder name="Entries" />} />
        <Route path="entries/new" element={<Placeholder name="New Entry" />} />
        <Route path="entries/:id" element={<Placeholder name="Entry Detail" />} />
        <Route path="approvals" element={<Placeholder name="Approvals" />} />
        <Route path="search" element={<Placeholder name="Search" />} />
      </Route>
    </Routes>
  );
}
```

- [ ] **Step 4: Verify build**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag/web && npm run build`
Expected: Build succeeds

- [ ] **Step 5: Commit**

```bash
cd /Users/freis/Projects/PERSONAL/rag
git add rag/web/src/components/Layout.tsx rag/web/src/components/EntryForm.tsx rag/web/src/App.tsx
git commit -m "feat: layout sidebar and shared entry form component"
```

---

## Task 8: Dashboard and Projects Pages

**Files:**
- Create: `rag/web/src/pages/Dashboard.tsx`
- Create: `rag/web/src/pages/Projects.tsx`
- Modify: `rag/web/src/App.tsx` (import pages)

- [ ] **Step 1: Create Dashboard.tsx**

Create `rag/web/src/pages/Dashboard.tsx`:

```typescript
import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Card, CardHeader, CardTitle, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { api, type Project, type Entry } from "@/lib/api";

export default function Dashboard() {
  const [projects, setProjects] = useState<Project[]>([]);
  const [recentEntries, setRecentEntries] = useState<Entry[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    async function load() {
      try {
        const projs = await api.listProjects();
        setProjects(projs);
        if (projs.length > 0) {
          const entries = await api.listEntries({ project_id: projs[0].id });
          setRecentEntries(entries.slice(0, 5));
        }
      } finally {
        setLoading(false);
      }
    }
    load();
  }, []);

  if (loading) return <div className="p-8 text-muted-foreground">Loading...</div>;

  const totalIndexed = projects.reduce((sum, p) => sum + (p.indexed_count ?? 0), 0);
  const totalPending = projects.reduce((sum, p) => sum + (p.pending_count ?? 0), 0);

  return (
    <div className="p-8 space-y-6">
      <h1 className="text-2xl font-bold">Dashboard</h1>
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <Card>
          <CardHeader><CardTitle className="text-sm">Projects</CardTitle></CardHeader>
          <CardContent><p className="text-3xl font-bold">{projects.length}</p></CardContent>
        </Card>
        <Card>
          <CardHeader><CardTitle className="text-sm">Indexed Entries</CardTitle></CardHeader>
          <CardContent><p className="text-3xl font-bold">{totalIndexed}</p></CardContent>
        </Card>
        <Card>
          <CardHeader><CardTitle className="text-sm">Pending</CardTitle></CardHeader>
          <CardContent><p className="text-3xl font-bold">{totalPending}</p></CardContent>
        </Card>
      </div>
      <div className="space-y-2">
        <h2 className="text-lg font-semibold">Recent Entries</h2>
        {recentEntries.length === 0 ? (
          <p className="text-muted-foreground">No entries yet.</p>
        ) : (
          <div className="space-y-2">
            {recentEntries.map((e) => (
              <Link key={e.id} to={`/entries/${e.id}`} className="block p-3 rounded-md border hover:bg-accent">
                <div className="flex items-center justify-between">
                  <span className="font-medium">{e.title}</span>
                  <Badge variant="secondary">{e.category}</Badge>
                </div>
                <p className="text-sm text-muted-foreground mt-1">{e.content.slice(0, 100)}...</p>
              </Link>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
```

- [ ] **Step 2: Create Projects.tsx**

Create `rag/web/src/pages/Projects.tsx`:

```typescript
import { useEffect, useState } from "react";
import { Card, CardHeader, CardTitle, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Badge } from "@/components/ui/badge";
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from "@/components/ui/table";
import { Dialog, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog";
import { api, type Project } from "@/lib/api";

export default function Projects() {
  const [projects, setProjects] = useState<Project[]>([]);
  const [loading, setLoading] = useState(true);
  const [showCreate, setShowCreate] = useState(false);
  const [editProject, setEditProject] = useState<Project | null>(null);
  const [deleteProject, setDeleteProject] = useState<Project | null>(null);

  const [form, setForm] = useState({ id: "", name: "", root_path: "", description: "", language: "en" });

  async function load() {
    try {
      setProjects(await api.listProjects());
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(); }, []);

  async function handleCreate() {
    await api.createProject(form);
    setShowCreate(false);
    setForm({ id: "", name: "", root_path: "", description: "", language: "en" });
    await load();
  }

  async function handleUpdate() {
    if (!editProject) return;
    await api.updateProject(editProject.id, {
      name: form.name,
      description: form.description,
      language: form.language,
    });
    setEditProject(null);
    await load();
  }

  async function handleDelete() {
    if (!deleteProject) return;
    await api.deleteProject(deleteProject.id);
    setDeleteProject(null);
    await load();
  }

  function openEdit(p: Project) {
    setEditProject(p);
    setForm({ id: p.id, name: p.name, root_path: p.root_path, description: p.description, language: p.language });
  }

  if (loading) return <div className="p-8 text-muted-foreground">Loading...</div>;

  return (
    <div className="p-8 space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">Projects</h1>
        <Button onClick={() => { setForm({ id: "", name: "", root_path: "", description: "", language: "en" }); setShowCreate(true); }}>
          New Project
        </Button>
      </div>

      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Name</TableHead>
            <TableHead>Path</TableHead>
            <TableHead>Language</TableHead>
            <TableHead>Indexed</TableHead>
            <TableHead>Pending</TableHead>
            <TableHead>Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {projects.map((p) => (
            <TableRow key={p.id}>
              <TableCell className="font-medium">{p.name}</TableCell>
              <TableCell className="text-muted-foreground text-xs">{p.root_path}</TableCell>
              <TableCell><Badge variant="outline">{p.language}</Badge></TableCell>
              <TableCell>{p.indexed_count}</TableCell>
              <TableCell>{p.pending_count}</TableCell>
              <TableCell>
                <div className="flex gap-2">
                  <Button size="sm" variant="ghost" onClick={() => openEdit(p)}>Edit</Button>
                  <Button size="sm" variant="ghost" className="text-destructive" onClick={() => setDeleteProject(p)}>Delete</Button>
                </div>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>

      {(showCreate || editProject) && (
        <Dialog open onOpenChange={() => { setShowCreate(false); setEditProject(null); }}>
          <DialogHeader><DialogTitle>{editProject ? "Edit Project" : "New Project"}</DialogTitle></DialogHeader>
          <div className="space-y-4">
            {!editProject && (
              <div className="space-y-2">
                <Label>Project ID</Label>
                <Input value={form.id} onChange={(e) => setForm({ ...form, id: e.target.value })} required />
              </div>
            )}
            <div className="space-y-2">
              <Label>Name</Label>
              <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
            </div>
            {!editProject && (
              <div className="space-y-2">
                <Label>Root Path</Label>
                <Input value={form.root_path} onChange={(e) => setForm({ ...form, root_path: e.target.value })} required />
              </div>
            )}
            <div className="space-y-2">
              <Label>Description</Label>
              <Input value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
            </div>
            <div className="space-y-2">
              <Label>Language</Label>
              <Select value={form.language} onChange={(e) => setForm({ ...form, language: e.target.value })}>
                <option value="en">English</option>
                <option value="pt-BR">Português (BR)</option>
                <option value="es">Español</option>
                <option value="fr">Français</option>
              </Select>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => { setShowCreate(false); setEditProject(null); }}>Cancel</Button>
            <Button onClick={editProject ? handleUpdate : handleCreate}>{editProject ? "Update" : "Create"}</Button>
          </DialogFooter>
        </Dialog>
      )}

      {deleteProject && (
        <Dialog open onOpenChange={() => setDeleteProject(null)}>
          <DialogHeader><DialogTitle>Delete Project</DialogTitle></DialogHeader>
          <p className="text-sm text-muted-foreground">
            Are you sure you want to delete "{deleteProject.name}"? This will remove all its entries.
          </p>
          <DialogFooter>
            <Button variant="outline" onClick={() => setDeleteProject(null)}>Cancel</Button>
            <Button variant="destructive" onClick={handleDelete}>Delete</Button>
          </DialogFooter>
        </Dialog>
      )}
    </div>
  );
}
```

- [ ] **Step 3: Update App.tsx to import pages**

Replace `rag/web/src/App.tsx`:

```typescript
import { Routes, Route } from "react-router-dom";
import Layout from "@/components/Layout";
import Dashboard from "@/pages/Dashboard";
import Projects from "@/pages/Projects";

function Placeholder({ name }: { name: string }) {
  return <div className="p-8 text-muted-foreground">{name} — coming soon</div>;
}

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<Layout />}>
        <Route index element={<Dashboard />} />
        <Route path="projects" element={<Projects />} />
        <Route path="entries" element={<Placeholder name="Entries" />} />
        <Route path="entries/new" element={<Placeholder name="New Entry" />} />
        <Route path="entries/:id" element={<Placeholder name="Entry Detail" />} />
        <Route path="approvals" element={<Placeholder name="Approvals" />} />
        <Route path="search" element={<Placeholder name="Search" />} />
      </Route>
    </Routes>
  );
}
```

- [ ] **Step 4: Verify build**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag/web && npm run build`
Expected: Build succeeds

- [ ] **Step 5: Commit**

```bash
cd /Users/freis/Projects/PERSONAL/rag
git add rag/web/src/pages/Dashboard.tsx rag/web/src/pages/Projects.tsx rag/web/src/App.tsx
git commit -m "feat: dashboard and projects pages with CRUD"
```

---

## Task 9: Entries, EntryDetail, and NewEntry Pages

**Files:**
- Create: `rag/web/src/pages/Entries.tsx`
- Create: `rag/web/src/pages/EntryDetail.tsx`
- Create: `rag/web/src/pages/NewEntry.tsx`
- Modify: `rag/web/src/App.tsx` (import pages)

- [ ] **Step 1: Create Entries.tsx**

Create `rag/web/src/pages/Entries.tsx`:

```typescript
import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Select } from "@/components/ui/select";
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from "@/components/ui/table";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { api, type Project, type Entry } from "@/lib/api";

const CATEGORIES = ["", "business-rule", "design-decision", "architecture", "documentation", "insight", "convention", "constraint"];

export default function Entries() {
  const [projects, setProjects] = useState<Project[]>([]);
  const [entries, setEntries] = useState<Entry[]>([]);
  const [loading, setLoading] = useState(true);
  const [projectId, setProjectId] = useState("");
  const [category, setCategory] = useState("");
  const [status, setStatus] = useState("indexed");

  useEffect(() => {
    async function load() {
      const projs = await api.listProjects();
      setProjects(projs);
      if (projs.length > 0) {
        setProjectId(projs[0].id);
      }
    }
    load();
  }, []);

  useEffect(() => {
    if (!projectId) return;
    setLoading(true);
    api.listEntries({ project_id: projectId, category: category || undefined, status: status === "all" ? undefined : status })
      .then(setEntries)
      .finally(() => setLoading(false));
  }, [projectId, category, status]);

  return (
    <div className="p-8 space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">Entries</h1>
        <Link to="/entries/new"><Button>New Entry</Button></Link>
      </div>

      <div className="flex gap-4 items-center">
        <Select value={projectId} onChange={(e) => setProjectId(e.target.value)} className="w-48">
          {projects.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
        </Select>
        <Select value={category} onChange={(e) => setCategory(e.target.value)} className="w-48">
          {CATEGORIES.map((c) => <option key={c} value={c}>{c || "All categories"}</option>)}
        </Select>
        <Tabs value={status} onValueChange={setStatus}>
          <TabsList>
            <TabsTrigger value="all">All</TabsTrigger>
            <TabsTrigger value="indexed">Indexed</TabsTrigger>
            <TabsTrigger value="pending">Pending</TabsTrigger>
            <TabsTrigger value="rejected">Rejected</TabsTrigger>
          </TabsList>
        </Tabs>
      </div>

      {loading ? (
        <p className="text-muted-foreground">Loading...</p>
      ) : entries.length === 0 ? (
        <p className="text-muted-foreground">No entries found.</p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Title</TableHead>
              <TableHead>Category</TableHead>
              <TableHead>Tags</TableHead>
              <TableHead>Status</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {entries.map((e) => (
              <TableRow key={e.id}>
                <TableCell>
                  <Link to={`/entries/${e.id}`} className="font-medium hover:underline">{e.title}</Link>
                </TableCell>
                <TableCell><Badge variant="secondary">{e.category}</Badge></TableCell>
                <TableCell className="text-xs text-muted-foreground">{e.tags.join(", ")}</TableCell>
                <TableCell>
                  <Badge variant={e.status === "indexed" ? "default" : e.status === "pending" ? "outline" : "destructive"}>
                    {e.status}
                  </Badge>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
    </div>
  );
}
```

- [ ] **Step 2: Create EntryDetail.tsx**

Create `rag/web/src/pages/EntryDetail.tsx`:

```typescript
import { useEffect, useState } from "react";
import { useParams, useNavigate } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Dialog, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog";
import EntryForm from "@/components/EntryForm";
import { api, type Entry, type Project } from "@/lib/api";

export default function EntryDetail() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [entry, setEntry] = useState<Entry | null>(null);
  const [projects, setProjects] = useState<Project[]>([]);
  const [editing, setEditing] = useState(false);
  const [showDelete, setShowDelete] = useState(false);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    async function load() {
      if (!id) return;
      try {
        const [e, projs] = await Promise.all([api.getEntry(id), api.listProjects()]);
        setEntry(e);
        setProjects(projs);
      } finally {
        setLoading(false);
      }
    }
    load();
  }, [id]);

  async function handleApprove() {
    if (!entry) return;
    await api.approveEntry(entry.id);
    setEntry(await api.getEntry(entry.id));
  }

  async function handleReject() {
    if (!entry) return;
    await api.rejectEntry(entry.id);
    setEntry(await api.getEntry(entry.id));
  }

  async function handleDelete() {
    if (!entry) return;
    await api.deleteEntry(entry.id);
    navigate("/entries");
  }

  if (loading) return <div className="p-8 text-muted-foreground">Loading...</div>;
  if (!entry) return <div className="p-8 text-muted-foreground">Entry not found.</div>;

  if (editing) {
    return (
      <div className="p-8">
        <h1 className="text-2xl font-bold mb-6">Edit Entry</h1>
        <EntryForm
          projects={projects}
          entry={entry}
          onSubmit={async () => { setEntry(await api.getEntry(entry.id)); setEditing(false); }}
          onCancel={() => setEditing(false)}
        />
      </div>
    );
  }

  return (
    <div className="p-8 space-y-6 max-w-3xl">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">{entry.title}</h1>
        <div className="flex gap-2">
          <Button variant="outline" onClick={() => setEditing(true)}>Edit</Button>
          <Button variant="destructive" onClick={() => setShowDelete(true)}>Delete</Button>
        </div>
      </div>

      <div className="flex gap-2 flex-wrap">
        <Badge variant="secondary">{entry.category}</Badge>
        <Badge variant={entry.status === "indexed" ? "default" : entry.status === "pending" ? "outline" : "destructive"}>
          {entry.status}
        </Badge>
        {entry.tags.map((t) => <Badge key={t} variant="outline">{t}</Badge>)}
      </div>

      <Card>
        <CardContent>
          <pre className="whitespace-pre-wrap text-sm mt-4">{entry.content}</pre>
        </CardContent>
      </Card>

      {entry.status === "pending" && (
        <div className="flex gap-2">
          <Button onClick={handleApprove}>Approve</Button>
          <Button variant="destructive" onClick={handleReject}>Reject</Button>
        </div>
      )}

      <div className="text-xs text-muted-foreground">
        <p>Source: {entry.source}</p>
        <p>Created: {new Date(entry.created_at * 1000).toLocaleString()}</p>
        <p>Updated: {new Date(entry.updated_at * 1000).toLocaleString()}</p>
      </div>

      {showDelete && (
        <Dialog open onOpenChange={setShowDelete}>
          <DialogHeader><DialogTitle>Delete Entry</DialogTitle></DialogHeader>
          <p className="text-sm text-muted-foreground">Are you sure you want to delete "{entry.title}"?</p>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowDelete(false)}>Cancel</Button>
            <Button variant="destructive" onClick={handleDelete}>Delete</Button>
          </DialogFooter>
        </Dialog>
      )}
    </div>
  );
}
```

- [ ] **Step 3: Create NewEntry.tsx**

Create `rag/web/src/pages/NewEntry.tsx`:

```typescript
import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import EntryForm from "@/components/EntryForm";
import { api, type Project } from "@/lib/api";

export default function NewEntry() {
  const navigate = useNavigate();
  const [projects, setProjects] = useState<Project[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.listProjects().then(setProjects).finally(() => setLoading(false));
  }, []);

  if (loading) return <div className="p-8 text-muted-foreground">Loading...</div>;
  if (projects.length === 0) return <div className="p-8 text-muted-foreground">Create a project first.</div>;

  return (
    <div className="p-8">
      <h1 className="text-2xl font-bold mb-6">New Entry</h1>
      <EntryForm
        projects={projects}
        onSubmit={() => navigate("/entries")}
        onCancel={() => navigate("/entries")}
      />
    </div>
  );
}
```

- [ ] **Step 4: Update App.tsx to import pages**

Replace `rag/web/src/App.tsx`:

```typescript
import { Routes, Route } from "react-router-dom";
import Layout from "@/components/Layout";
import Dashboard from "@/pages/Dashboard";
import Projects from "@/pages/Projects";
import Entries from "@/pages/Entries";
import EntryDetail from "@/pages/EntryDetail";
import NewEntry from "@/pages/NewEntry";

function Placeholder({ name }: { name: string }) {
  return <div className="p-8 text-muted-foreground">{name} — coming soon</div>;
}

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<Layout />}>
        <Route index element={<Dashboard />} />
        <Route path="projects" element={<Projects />} />
        <Route path="entries" element={<Entries />} />
        <Route path="entries/new" element={<NewEntry />} />
        <Route path="entries/:id" element={<EntryDetail />} />
        <Route path="approvals" element={<Placeholder name="Approvals" />} />
        <Route path="search" element={<Placeholder name="Search" />} />
      </Route>
    </Routes>
  );
}
```

- [ ] **Step 5: Verify build**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag/web && npm run build`
Expected: Build succeeds

- [ ] **Step 6: Commit**

```bash
cd /Users/freis/Projects/PERSONAL/rag
git add rag/web/src/pages/Entries.tsx rag/web/src/pages/EntryDetail.tsx rag/web/src/pages/NewEntry.tsx rag/web/src/App.tsx
git commit -m "feat: entries list, entry detail with edit/delete, and new entry form"
```

---

## Task 10: Approvals and Search Pages

**Files:**
- Create: `rag/web/src/pages/Approvals.tsx`
- Create: `rag/web/src/pages/Search.tsx`
- Modify: `rag/web/src/App.tsx` (import remaining pages)

- [ ] **Step 1: Create Approvals.tsx**

Create `rag/web/src/pages/Approvals.tsx`:

```typescript
import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Card, CardHeader, CardTitle, CardContent } from "@/components/ui/card";
import { api, type Project, type Entry } from "@/lib/api";

export default function Approvals() {
  const [projects, setProjects] = useState<Project[]>([]);
  const [pendingByProject, setPendingByProject] = useState<Record<string, Entry[]>>({});
  const [loading, setLoading] = useState(true);

  async function load() {
    try {
      const projs = await api.listProjects();
      setProjects(projs);
      const pendingMap: Record<string, Entry[]> = {};
      for (const p of projs) {
        const entries = await api.listEntries({ project_id: p.id, status: "pending" });
        if (entries.length > 0) {
          pendingMap[p.id] = entries;
        }
      }
      setPendingByProject(pendingMap);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(); }, []);

  async function approveEntry(id: string, projectId: string) {
    await api.approveEntry(id);
    const updated = pendingByProject[projectId].filter((e) => e.id !== id);
    setPendingByProject({ ...pendingByProject, [projectId]: updated });
  }

  async function rejectEntry(id: string, projectId: string) {
    await api.rejectEntry(id);
    const updated = pendingByProject[projectId].filter((e) => e.id !== id);
    setPendingByProject({ ...pendingByProject, [projectId]: updated });
  }

  async function approveAll(projectId: string) {
    await api.approveAll(projectId);
    setPendingByProject({ ...pendingByProject, [projectId]: [] });
  }

  async function rejectAll(projectId: string) {
    await api.rejectAll(projectId);
    setPendingByProject({ ...pendingByProject, [projectId]: [] });
  }

  if (loading) return <div className="p-8 text-muted-foreground">Loading...</div>;

  const hasPending = Object.values(pendingByProject).some((entries) => entries.length > 0);

  if (!hasPending) {
    return <div className="p-8"><h1 className="text-2xl font-bold mb-4">Approvals</h1><p className="text-muted-foreground">No pending entries. Everything is reviewed.</p></div>;
  }

  return (
    <div className="p-8 space-y-6">
      <h1 className="text-2xl font-bold">Approvals</h1>
      {projects.filter((p) => pendingByProject[p.id]?.length > 0).map((p) => (
        <Card key={p.id}>
          <CardHeader>
            <div className="flex items-center justify-between">
              <CardTitle>{p.name}</CardTitle>
              <div className="flex gap-2">
                <Button size="sm" onClick={() => approveAll(p.id)}>Approve All</Button>
                <Button size="sm" variant="destructive" onClick={() => rejectAll(p.id)}>Reject All</Button>
              </div>
            </div>
          </CardHeader>
          <CardContent className="space-y-3">
            {pendingByProject[p.id].map((e) => (
              <div key={e.id} className="border rounded-md p-3 space-y-2">
                <div className="flex items-center justify-between">
                  <span className="font-medium">{e.title}</span>
                  <Badge variant="secondary">{e.category}</Badge>
                </div>
                {e.tags.length > 0 && (
                  <div className="flex gap-1 flex-wrap">
                    {e.tags.map((t) => <Badge key={t} variant="outline">{t}</Badge>)}
                  </div>
                )}
                <pre className="text-sm text-muted-foreground whitespace-pre-wrap">{e.content.slice(0, 300)}{e.content.length > 300 ? "..." : ""}</pre>
                <div className="flex gap-2">
                  <Button size="sm" onClick={() => approveEntry(e.id, p.id)}>Approve</Button>
                  <Button size="sm" variant="destructive" onClick={() => rejectEntry(e.id, p.id)}>Reject</Button>
                </div>
              </div>
            ))}
          </CardContent>
        </Card>
      ))}
    </div>
  );
}
```

- [ ] **Step 2: Create Search.tsx**

Create `rag/web/src/pages/Search.tsx`:

```typescript
import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { api, type Project, type SearchResult } from "@/lib/api";

const CATEGORIES = ["", "business-rule", "design-decision", "architecture", "documentation", "insight", "convention", "constraint"];

export default function Search() {
  const [projects, setProjects] = useState<Project[]>([]);
  const [projectId, setProjectId] = useState("");
  const [query, setQuery] = useState("");
  const [category, setCategory] = useState("");
  const [results, setResults] = useState<SearchResult[]>([]);
  const [searching, setSearching] = useState(false);

  useEffect(() => {
    api.listProjects().then((projs) => {
      setProjects(projs);
      if (projs.length > 0) setProjectId(projs[0].id);
    });
  }, []);

  useEffect(() => {
    if (!query.trim() || !projectId) {
      setResults([]);
      return;
    }
    const timer = setTimeout(async () => {
      setSearching(true);
      try {
        const r = await api.search({ q: query, project_id: projectId, category: category || undefined });
        setResults(r);
      } finally {
        setSearching(false);
      }
    }, 300);
    return () => clearTimeout(timer);
  }, [query, projectId, category]);

  return (
    <div className="p-8 space-y-6 max-w-3xl">
      <h1 className="text-2xl font-bold">Search</h1>
      <Input
        placeholder="Search the knowledge base..."
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        className="text-lg"
      />
      <div className="flex gap-4">
        <Select value={projectId} onChange={(e) => setProjectId(e.target.value)} className="w-48">
          {projects.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
        </Select>
        <Select value={category} onChange={(e) => setCategory(e.target.value)} className="w-48">
          {CATEGORIES.map((c) => <option key={c} value={c}>{c || "All categories"}</option>)}
        </Select>
      </div>

      {searching && <p className="text-muted-foreground">Searching...</p>}

      {results.length > 0 && (
        <div className="space-y-3">
          {results.map((r) => (
            <Card key={r.id}>
              <CardContent className="pt-4">
                <Link to={`/entries/${r.id}`} className="block">
                  <div className="flex items-center justify-between mb-2">
                    <span className="font-medium hover:underline">{r.title}</span>
                    <div className="flex gap-2">
                      <Badge variant="secondary">{r.category}</Badge>
                      <Badge variant="outline">score: {r.score}</Badge>
                    </div>
                  </div>
                  <p className="text-sm text-muted-foreground">{r.content.slice(0, 200)}...</p>
                  {r.tags.length > 0 && (
                    <div className="flex gap-1 mt-2">
                      {r.tags.map((t) => <Badge key={t} variant="outline">{t}</Badge>)}
                    </div>
                  )}
                </Link>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      {!searching && query.trim() && results.length === 0 && projectId && (
        <p className="text-muted-foreground">No results found.</p>
      )}
    </div>
  );
}
```

- [ ] **Step 3: Update App.tsx to import all pages**

Replace `rag/web/src/App.tsx`:

```typescript
import { Routes, Route } from "react-router-dom";
import Layout from "@/components/Layout";
import Dashboard from "@/pages/Dashboard";
import Projects from "@/pages/Projects";
import Entries from "@/pages/Entries";
import EntryDetail from "@/pages/EntryDetail";
import NewEntry from "@/pages/NewEntry";
import Approvals from "@/pages/Approvals";
import Search from "@/pages/Search";

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<Layout />}>
        <Route index element={<Dashboard />} />
        <Route path="projects" element={<Projects />} />
        <Route path="entries" element={<Entries />} />
        <Route path="entries/new" element={<NewEntry />} />
        <Route path="entries/:id" element={<EntryDetail />} />
        <Route path="approvals" element={<Approvals />} />
        <Route path="search" element={<Search />} />
      </Route>
    </Routes>
  );
}
```

- [ ] **Step 4: Verify build**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag/web && npm run build`
Expected: Build succeeds

- [ ] **Step 5: Commit**

```bash
cd /Users/freis/Projects/PERSONAL/rag
git add rag/web/src/pages/Approvals.tsx rag/web/src/pages/Search.tsx rag/web/src/App.tsx
git commit -m "feat: approvals and search pages with debounced search"
```

---

## Task 11: Docker Configuration

**Files:**
- Create: `rag/Dockerfile.api`
- Create: `rag/Dockerfile.web`
- Create: `rag/nginx.conf`
- Modify: `rag/docker-compose.yml` (replace with 2 services)
- Delete: `rag/Dockerfile`

- [ ] **Step 1: Create Dockerfile.api**

Create `rag/Dockerfile.api`:

```dockerfile
FROM python:3.12-slim

WORKDIR /app

COPY rag/requirements.txt /app/requirements.txt
RUN pip3 install --no-cache-dir -r requirements.txt

COPY rag/server/ /app/server/

ENV PYTHONUNBUFFERED=1
ENV PYTHONPATH=/app/server

EXPOSE 8000

CMD ["uvicorn", "server.api:app", "--host", "0.0.0.0", "--port", "8000"]
```

- [ ] **Step 2: Create Dockerfile.web**

Create `rag/Dockerfile.web`:

```dockerfile
FROM node:20-slim AS build
WORKDIR /app
COPY rag/web/package*.json ./
RUN npm ci
COPY rag/web/ ./
RUN npm run build

FROM nginx:alpine
COPY --from=build /app/dist /usr/share/nginx/html
COPY nginx.conf /etc/nginx/conf.d/default.conf
EXPOSE 80
```

- [ ] **Step 3: Create nginx.conf**

Create `rag/nginx.conf`:

```nginx
server {
    listen 80;
    root /usr/share/nginx/html;
    index index.html;

    location /api/ {
        proxy_pass http://rag-api:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    }

    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

- [ ] **Step 4: Replace docker-compose.yml**

Replace `rag/docker-compose.yml`:

```yaml
services:
  api:
    build:
      context: .
      dockerfile: Dockerfile.api
    container_name: rag-api
    ports:
      - "8000:8000"
    volumes:
      - ~/.rag:/root/.rag
    restart: unless-stopped

  web:
    build:
      context: .
      dockerfile: Dockerfile.web
    container_name: rag-web
    ports:
      - "8765:80"
    depends_on:
      - api
    restart: unless-stopped
```

- [ ] **Step 5: Delete old Dockerfile**

```bash
rm /Users/freis/Projects/PERSONAL/rag/Dockerfile
```

- [ ] **Step 6: Build and verify containers**

Run:
```bash
cd /Users/freis/Projects/PERSONAL/rag
docker compose down 2>/dev/null || true
docker compose build
docker compose up -d
sleep 3
curl -s http://127.0.0.1:8000/api/projects | head -1
curl -s http://127.0.0.1:8765/ | head -1
```
Expected: API returns JSON (empty array or projects), web returns HTML (`<!DOCTYPE html>`)

- [ ] **Step 7: Commit**

```bash
cd /Users/freis/Projects/PERSONAL/rag
git add Dockerfile.api Dockerfile.web nginx.conf docker-compose.yml
git rm Dockerfile
git commit -m "feat: Docker config with separate API and web containers"
```

---

## Task 12: Update README and Final Verification

**Files:**
- Modify: `rag/README.md`

- [ ] **Step 1: Update README Docker section**

In `rag/README.md`, replace the Docker section with:

```markdown
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
```

- [ ] **Step 2: Update README architecture section**

In `rag/README.md`, replace the Architecture section with:

```markdown
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
```

- [ ] **Step 3: Run all tests**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag && python3 -m pytest tests/ -v`
Expected: ALL tests PASS

- [ ] **Step 4: Verify Docker end-to-end**

Run:
```bash
cd /Users/freis/Projects/PERSONAL/rag
docker compose down 2>/dev/null || true
docker compose up -d --build
sleep 3
# Test API
curl -s http://127.0.0.1:8000/api/projects
# Test web
curl -s http://127.0.0.1:8765/ | head -1
# Test search API
curl -s "http://127.0.0.1:8000/api/search?q=test&project_id=refresh"
```
Expected: API returns JSON, web returns HTML

- [ ] **Step 5: Commit**

```bash
cd /Users/freis/Projects/PERSONAL/rag
git add README.md
git commit -m "docs: update README with admin panel architecture and Docker setup"
```

---

## Self-Review Checklist

**Spec coverage:**
- [x] FastAPI REST API with project CRUD — Task 1
- [x] Entry CRUD (create, read, update, delete) — Task 2
- [x] Search endpoint (TF-IDF) — Task 3
- [x] Tags endpoint — Task 3
- [x] Approve/reject individual and batch — Task 2 (individual) + Task 1 (batch)
- [x] React + Vite + shadcn/ui scaffold — Task 5
- [x] shadcn/ui base components — Task 6
- [x] Layout with sidebar nav — Task 7
- [x] Shared EntryForm component — Task 7
- [x] Dashboard page — Task 8
- [x] Projects page with CRUD — Task 8
- [x] Entries list with filters — Task 9
- [x] Entry detail with edit/delete — Task 9
- [x] New entry form — Task 9
- [x] Approvals page — Task 10
- [x] Search page with debounce — Task 10
- [x] Dockerfile.api — Task 11
- [x] Dockerfile.web — Task 11
- [x] nginx.conf — Task 11
- [x] docker-compose.yml with 2 services — Task 11
- [x] Delete old web_ui.py and approval.html — Task 4
- [x] Update main.py to use api — Task 4
- [x] Update README — Task 12
- [x] MCP server continues via stdio — Task 4

**Placeholder scan:** No TBD, TODO, or "implement later" found. All code blocks are complete.

**Type consistency:** `Project`, `Entry`, `SearchResult`, `ProjectCreate`, `EntryCreate`, `EntryUpdate` types defined in `api.ts` and used consistently across all pages. API endpoint paths match between `api.py` and `api.ts`. `start_api_server` signature matches between `api.py` and `main.py`.
