# Multi-Repo Project Paths Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow a single RAG project to be associated with multiple filesystem paths, so an AI assistant working in either the frontend or backend repo resolves to the same project.

**Architecture:** New `project_paths` table maps multiple paths to a project. `get_project_by_path` queries this table instead of `projects.root_path`. New API endpoints and MCP tool manage paths. Frontend Projects page gains an "Additional Paths" section.

**Tech Stack:** Python 3.12, FastAPI, SQLite, React, TypeScript

---

## File Structure

```
rag/
├── server/
│   ├── db.py                  ← MODIFY: project_paths table, new functions, modified get_project_by_path/list_projects/upsert_project
│   ├── api.py                 ← MODIFY: new path endpoints, paths in project responses
│   └── main.py                ← MODIFY: new rag_add_project_path tool, _list_projects shows paths
├── tests/
│   ├── test_db.py             ← MODIFY: add project_paths tests
│   └── test_api.py            ← MODIFY: add path endpoint tests
└── web/
    └── src/
        ├── lib/api.ts         ← MODIFY: Project interface gains paths, new API methods
        └── pages/Projects.tsx ← MODIFY: Additional Paths section in dialog, +N badge in table
```

---

## Task 1: db.py — project_paths table + functions

**Files:**
- Modify: `rag/server/db.py`
- Modify: `rag/tests/test_db.py`

**Interfaces:**
- Produces: `add_project_path(project_id, path)`, `remove_project_path(project_id, path)`, `list_project_paths(project_id)`, modified `get_project_by_path(path)`, modified `list_projects()`, modified `upsert_project()`

- [ ] **Step 1: Write failing tests for project_paths**

Append to `rag/tests/test_db.py`:

```python
class TestProjectPaths:
    def test_add_project_path(self, temp_db):
        temp_db.upsert_project("p1", "Proj1", "/tmp/p1")
        temp_db.add_project_path("p1", "/tmp/p1-frontend")
        paths = temp_db.list_project_paths("p1")
        assert "/tmp/p1-frontend" in paths
        assert "/tmp/p1" in paths

    def test_add_duplicate_path_ignored(self, temp_db):
        temp_db.upsert_project("p1", "Proj1", "/tmp/p1")
        temp_db.add_project_path("p1", "/tmp/p1-frontend")
        temp_db.add_project_path("p1", "/tmp/p1-frontend")
        paths = temp_db.list_project_paths("p1")
        assert paths.count("/tmp/p1-frontend") == 1

    def test_remove_project_path(self, temp_db):
        temp_db.upsert_project("p1", "Proj1", "/tmp/p1")
        temp_db.add_project_path("p1", "/tmp/p1-frontend")
        temp_db.remove_project_path("p1", "/tmp/p1-frontend")
        paths = temp_db.list_project_paths("p1")
        assert "/tmp/p1-frontend" not in paths

    def test_remove_last_path_raises(self, temp_db):
        temp_db.upsert_project("p1", "Proj1", "/tmp/p1")
        with pytest.raises(ValueError, match="last"):
            temp_db.remove_project_path("p1", "/tmp/p1")

    def test_get_project_by_path_finds_additional_path(self, temp_db):
        temp_db.upsert_project("p1", "Proj1", "/tmp/p1")
        temp_db.add_project_path("p1", "/tmp/p1-frontend")
        project = temp_db.get_project_by_path("/tmp/p1-frontend")
        assert project is not None
        assert project["id"] == "p1"

    def test_list_projects_includes_paths(self, temp_db):
        temp_db.upsert_project("p1", "Proj1", "/tmp/p1")
        temp_db.add_project_path("p1", "/tmp/p1-frontend")
        projects = temp_db.list_projects()
        assert "paths" in projects[0]
        assert "/tmp/p1" in projects[0]["paths"]
        assert "/tmp/p1-frontend" in projects[0]["paths"]

    def test_upsert_project_ensures_root_path_in_project_paths(self, temp_db):
        temp_db.upsert_project("p1", "Proj1", "/tmp/p1")
        paths = temp_db.list_project_paths("p1")
        assert "/tmp/p1" in paths
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag && python3 -m pytest tests/test_db.py::TestProjectPaths -v`
Expected: FAIL — `add_project_path` not defined

- [ ] **Step 3: Add project_paths table to init_db()**

In `rag/server/db.py`, inside `init_db()`, after the `CREATE INDEX IF NOT EXISTS idx_entry_tags_entry` line and before the `conn.commit()`, add:

```python
        conn.execute("""
            CREATE TABLE IF NOT EXISTS project_paths (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                path TEXT NOT NULL,
                UNIQUE(project_id, path)
            )
        """)
```

After the migration block for `language` column (after `conn.commit()` in the language migration), add the root_path migration:

```python
        # Migrate existing root_path values to project_paths
        projects = conn.execute("SELECT id, root_path FROM projects").fetchall()
        for p in projects:
            conn.execute(
                "INSERT OR IGNORE INTO project_paths (project_id, path) VALUES (?, ?)",
                (p["id"], p["root_path"]),
            )
        conn.commit()
```

- [ ] **Step 4: Add new project_paths functions**

In `rag/server/db.py`, after the `get_project_by_path` function, add:

```python
def add_project_path(project_id, path):
    """Associate an additional path with a project."""
    path = os.path.abspath(path)
    conn = get_connection()
    try:
        conn.execute(
            "INSERT OR IGNORE INTO project_paths (project_id, path) VALUES (?, ?)",
            (project_id, path),
        )
        conn.commit()
    finally:
        conn.close()


def remove_project_path(project_id, path):
    """Remove a path from a project. Raises ValueError if it's the last path."""
    path = os.path.abspath(path)
    conn = get_connection()
    try:
        count = conn.execute(
            "SELECT COUNT(*) as cnt FROM project_paths WHERE project_id = ?",
            (project_id,),
        ).fetchone()
        if count["cnt"] <= 1:
            raise ValueError("Cannot remove the last path from a project")
        conn.execute(
            "DELETE FROM project_paths WHERE project_id = ? AND path = ?",
            (project_id, path),
        )
        conn.commit()
    finally:
        conn.close()


def list_project_paths(project_id):
    """List all paths for a project."""
    conn = get_connection()
    try:
        rows = conn.execute(
            "SELECT path FROM project_paths WHERE project_id = ? ORDER BY path",
            (project_id,),
        ).fetchall()
        return [r["path"] for r in rows]
    finally:
        conn.close()
```

- [ ] **Step 5: Modify get_project_by_path to query project_paths**

Replace the existing `get_project_by_path` function:

```python
def get_project_by_path(path):
    path = os.path.abspath(path)
    conn = get_connection()
    try:
        row = conn.execute("""
            SELECT p.* FROM projects p
            JOIN project_paths pp ON pp.project_id = p.id
            WHERE pp.path = ?
        """, (path,)).fetchone()
        return dict(row) if row else None
    finally:
        conn.close()
```

- [ ] **Step 6: Modify list_projects to include paths**

Replace the `list_projects` function:

```python
def list_projects():
    conn = get_connection()
    try:
        rows = conn.execute("""
            SELECT p.*,
                   (SELECT COUNT(*) FROM knowledge_entries e WHERE e.project_id = p.id AND e.status = 'indexed') as indexed_count,
                   (SELECT COUNT(*) FROM knowledge_entries e WHERE e.project_id = p.id AND e.status = 'pending') as pending_count
            FROM projects p ORDER BY p.updated_at DESC
        """).fetchall()
        projects = []
        for r in rows:
            p = dict(r)
            p["paths"] = list_project_paths(p["id"])
            projects.append(p)
        return projects
    finally:
        conn.close()
```

- [ ] **Step 7: Modify upsert_project to ensure root_path in project_paths**

In `upsert_project`, after the `conn.commit()` line (the one inside the try block, after the if/else), add:

```python
        conn.execute(
            "INSERT OR IGNORE INTO project_paths (project_id, path) VALUES (?, ?)",
            (project_id, os.path.abspath(root_path)),
        )
        conn.commit()
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag && python3 -m pytest tests/test_db.py::TestProjectPaths -v`
Expected: PASS — all 7 tests green

- [ ] **Step 9: Run full test suite**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag && RAG_EMBEDDING_MODEL=all-MiniLM-L6-v2 RAG_EMBEDDING_DIM=384 python3 -m pytest tests/ -v`
Expected: All tests pass

- [ ] **Step 10: Commit**

```bash
cd /Users/freis/Projects/PERSONAL/rag
git add rag/server/db.py rag/tests/test_db.py
git commit -m "feat: project_paths table with multi-path support in db layer"
```

---

## Task 2: api.py — Path endpoints + paths in responses

**Files:**
- Modify: `rag/server/api.py`
- Modify: `rag/tests/test_api.py`

**Interfaces:**
- Consumes: `db.add_project_path`, `db.remove_project_path`, `db.list_project_paths`, `db.list_projects` (modified), `db.get_project` (modified)
- Produces: `POST /api/projects/{id}/paths`, `DELETE /api/projects/{id}/paths`, `GET /api/projects/{id}/paths`

- [ ] **Step 1: Write failing tests for path endpoints**

Append to `rag/tests/test_api.py`:

```python
class TestProjectPaths:
    def _setup_project(self, client):
        client.post("/api/projects", json={
            "id": "test-proj", "name": "Test", "root_path": "/tmp/test",
        })

    def test_list_paths(self, client):
        self._setup_project(client)
        resp = client.get("/api/projects/test-proj/paths")
        assert resp.status_code == 200
        paths = resp.json()
        assert "/tmp/test" in paths

    def test_add_path(self, client):
        self._setup_project(client)
        resp = client.post("/api/projects/test-proj/paths", json={"path": "/tmp/frontend"})
        assert resp.status_code == 200
        assert "/tmp/frontend" in resp.json()["paths"]

    def test_add_duplicate_path(self, client):
        self._setup_project(client)
        client.post("/api/projects/test-proj/paths", json={"path": "/tmp/frontend"})
        resp = client.post("/api/projects/test-proj/paths", json={"path": "/tmp/frontend"})
        assert resp.status_code == 200
        paths = resp.json()["paths"]
        assert paths.count("/tmp/frontend") == 1

    def test_remove_path(self, client):
        self._setup_project(client)
        client.post("/api/projects/test-proj/paths", json={"path": "/tmp/frontend"})
        resp = client.delete("/api/projects/test-proj/paths?path=/tmp/frontend")
        assert resp.status_code == 204
        paths = client.get("/api/projects/test-proj/paths").json()
        assert "/tmp/frontend" not in paths

    def test_remove_last_path_returns_400(self, client):
        self._setup_project(client)
        resp = client.delete("/api/projects/test-proj/paths?path=/tmp/test")
        assert resp.status_code == 400

    def test_project_response_includes_paths(self, client):
        self._setup_project(client)
        resp = client.get("/api/projects/test-proj")
        assert resp.status_code == 200
        assert "paths" in resp.json()
        assert "/tmp/test" in resp.json()["paths"]

    def test_list_projects_includes_paths(self, client):
        self._setup_project(client)
        resp = client.get("/api/projects")
        assert resp.status_code == 200
        assert "paths" in resp.json()[0]
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag && python3 -m pytest tests/test_api.py::TestProjectPaths -v`
Expected: FAIL — path endpoints not defined

- [ ] **Step 3: Add Pydantic model for path creation**

In `rag/server/api.py`, after the `EntryUpdate` model, add:

```python
class PathCreate(BaseModel):
    path: str
```

- [ ] **Step 4: Add path endpoints**

In `rag/server/api.py`, after the `reject_all` endpoint and before the `# ---- Entry endpoints ----` section, add:

```python
@app.get("/api/projects/{project_id}/paths")
def list_project_paths(project_id: str):
    proj = db.get_project(project_id)
    if not proj:
        raise HTTPException(404, "Project not found")
    return db.list_project_paths(project_id)


@app.post("/api/projects/{project_id}/paths")
def add_project_path(project_id: str, data: PathCreate):
    proj = db.get_project(project_id)
    if not proj:
        raise HTTPException(404, "Project not found")
    db.add_project_path(project_id, data.path)
    return db.get_project(project_id)


@app.delete("/api/projects/{project_id}/paths", status_code=204)
def remove_project_path(project_id: str, path: str = Query(...)):
    proj = db.get_project(project_id)
    if not proj:
        raise HTTPException(404, "Project not found")
    try:
        db.remove_project_path(project_id, path)
    except ValueError:
        raise HTTPException(400, "Cannot remove the last path from a project")
    return None
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag && python3 -m pytest tests/test_api.py::TestProjectPaths -v`
Expected: PASS — all 8 tests green

- [ ] **Step 6: Run full test suite**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag && RAG_EMBEDDING_MODEL=all-MiniLM-L6-v2 RAG_EMBEDDING_DIM=384 python3 -m pytest tests/ -v`
Expected: All tests pass

- [ ] **Step 7: Commit**

```bash
cd /Users/freis/Projects/PERSONAL/rag
git add rag/server/api.py rag/tests/test_api.py
git commit -m "feat: API endpoints for project path management"
```

---

## Task 3: main.py — MCP tool + resolution changes

**Files:**
- Modify: `rag/server/main.py`

**Interfaces:**
- Consumes: `db.add_project_path`, `db.list_project_paths`, `db.get_project_by_path` (modified)
- Produces: `rag_add_project_path` MCP tool, modified `_list_projects` output

- [ ] **Step 1: Add rag_add_project_path to TOOLS list**

In `rag/server/main.py`, find the `TOOLS` list. After the `rag_set_language` tool definition, add:

```python
    {
        "name": "rag_add_project_path",
        "description": (
            "Associate an additional filesystem path with an existing project. "
            "Useful for multi-repo projects (e.g., separate frontend and backend repos)."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "project_id": {
                    "type": "string",
                    "description": "Project ID",
                },
                "path": {
                    "type": "string",
                    "description": "Filesystem path to associate",
                },
            },
            "required": ["project_id", "path"],
        },
    },
```

- [ ] **Step 2: Add tool handler**

In `rag/server/main.py`, find the tool dispatch section (the `if name == ...` chain). After the `rag_set_language` handler, add:

```python
        elif name == "rag_add_project_path":
            return _add_project_path(args)
```

Then, after the `_set_language` function, add:

```python
def _add_project_path(args):
    pid = args["project_id"]
    path = args["path"]

    project = db.get_project(pid)
    if not project:
        return {"content": [{"type": "text", "text": f"Project '{pid}' not found."}]}

    db.add_project_path(pid, path)
    paths = db.list_project_paths(pid)
    paths_str = "\n  ".join(paths)

    return {"content": [{"type": "text", "text": f"Path added to project '{project['name']}'.\n  {paths_str}"}]}
```

- [ ] **Step 3: Update _list_projects to show paths**

Replace the `_list_projects` function:

```python
def _list_projects(args):
    projects = db.list_projects()
    if not projects:
        return {"content": [{"type": "text", "text": "No projects registered. Store knowledge to create one."}]}

    lines = ["Projects in Knowledge Base:\n"]
    for p in projects:
        lines.append(f"  {p['name']} ({p['id']})")
        for path in p.get("paths", [p["root_path"]]):
            lines.append(f"    Path: {path}")
        lines.append(f"    Language: {p.get('language', 'en')}")
        lines.append(f"    Indexed: {p['indexed_count']} | Pending: {p['pending_count']}\n")

    return {"content": [{"type": "text", "text": "\n".join(lines)}]}
```

- [ ] **Step 4: Verify MCP server starts**

Run: `echo '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' | python3 /Users/freis/Projects/PERSONAL/rag/rag/server/main.py 2>/dev/null | head -1`
Expected: JSON response with `"name":"rag"`

- [ ] **Step 5: Run all tests**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag && RAG_EMBEDDING_MODEL=all-MiniLM-L6-v2 RAG_EMBEDDING_DIM=384 python3 -m pytest tests/ -v`
Expected: All tests pass

- [ ] **Step 6: Commit**

```bash
cd /Users/freis/Projects/PERSONAL/rag
git add rag/server/main.py
git commit -m "feat: rag_add_project_path MCP tool and multi-path project listing"
```

---

## Task 4: Frontend — api.ts + Projects.tsx

**Files:**
- Modify: `rag/web/src/lib/api.ts`
- Modify: `rag/web/src/pages/Projects.tsx`

- [ ] **Step 1: Update api.ts — Project interface and new methods**

In `rag/web/src/lib/api.ts`, add `paths` to the `Project` interface (after `pending_count`):

```typescript
  paths?: string[];
```

Add new API methods to the `api` object (after `rejectAll`):

```typescript
  addProjectPath: (projectId: string, path: string) =>
    fetchJSON<Project>(`/projects/${projectId}/paths`, { method: "POST", body: JSON.stringify({ path }) }),
  removeProjectPath: (projectId: string, path: string) =>
    fetchJSON<void>(`/projects/${projectId}/paths?path=${encodeURIComponent(path)}`, { method: "DELETE" }),
```

- [ ] **Step 2: Update Projects.tsx — add paths state and Additional Paths section**

In `rag/web/src/pages/Projects.tsx`:

**a) Add state for additional paths** (after the `form` state on line 19):

```typescript
  const [additionalPaths, setAdditionalPaths] = useState<string[]>([]);
  const [newPath, setNewPath] = useState("");
```

**b) Update form reset** — in `handleCreate` and the "New Project" button, reset additional paths:

In `handleCreate`, after `setForm(...)`:
```typescript
    setAdditionalPaths([]);
    setNewPath("");
```

In the "New Project" button onClick:
```typescript
    setForm({ id: "", name: "", root_path: "", description: "", language: "en" });
    setAdditionalPaths([]);
    setNewPath("");
    setShowCreate(true);
```

**c) Update openEdit** to load existing paths:

```typescript
  function openEdit(p: Project) {
    setEditProject(p);
    setForm({ id: p.id, name: p.name, root_path: p.root_path, description: p.description, language: p.language });
    setAdditionalPaths((p.paths ?? []).filter(path => path !== p.root_path));
    setNewPath("");
  }
```

**d) Add path management functions** (after `openEdit`):

```typescript
  async function addPath() {
    if (!newPath.trim() || !editProject) return;
    await api.addProjectPath(editProject.id, newPath.trim());
    setAdditionalPaths([...additionalPaths, newPath.trim()]);
    setNewPath("");
  }

  async function removePath(path: string) {
    if (!editProject) return;
    await api.removeProjectPath(editProject.id, path);
    setAdditionalPaths(additionalPaths.filter(p => p !== path));
  }
```

**e) Update the table Path column** to show +N badge:

Replace the Path TableCell:
```tsx
              <TableCell className="text-muted-foreground text-xs">
                {p.root_path}
                {(p.paths ?? []).length > 1 && (
                  <Badge variant="secondary" className="ml-1">+{(p.paths ?? []).length - 1}</Badge>
                )}
              </TableCell>
```

**f) Add Additional Paths section in the dialog** — after the Language Select, before `</div>` that closes the form fields:

```tsx
            {editProject && (
              <div className="space-y-2">
                <Label>Additional Paths</Label>
                {additionalPaths.map((path) => (
                  <div key={path} className="flex items-center gap-2">
                    <span className="text-sm text-muted-foreground flex-1">{path}</span>
                    <Button size="sm" variant="ghost" className="text-destructive" onClick={() => removePath(path)}>Remove</Button>
                  </div>
                ))}
                <div className="flex gap-2">
                  <Input
                    value={newPath}
                    onChange={(e) => setNewPath(e.target.value)}
                    placeholder="/path/to/another/repo"
                    onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); addPath(); } }}
                  />
                  <Button type="button" size="sm" onClick={addPath}>Add</Button>
                </div>
              </div>
            )}
```

- [ ] **Step 3: Verify build**

Run: `cd /Users/freis/Projects/PERSONAL/rag/rag/web && npm run build`
Expected: Build succeeds

- [ ] **Step 4: Commit**

```bash
cd /Users/freis/Projects/PERSONAL/rag
git add rag/web/src/lib/api.ts rag/web/src/pages/Projects.tsx
git commit -m "feat: multi-repo path management in admin panel"
```
