# Multi-Repo Project Paths Design Spec

**Goal:** Allow a single RAG project to be associated with multiple filesystem paths, so an AI assistant working in either the frontend or backend repo resolves to the same project and shares the same knowledge base.

**Date:** 2026-07-06

---

## Context

Currently, a project has a single `root_path` column in the `projects` table. When the AI is in a different repo (e.g., the frontend repo), it resolves to a different project or fails to resolve — even though both repos belong to the same logical project. This means knowledge stored while in the backend repo is not accessible from the frontend repo.

## Decision

Add a `project_paths` table that maps multiple paths to a single project. The `root_path` column stays for backward compatibility (it becomes the primary path). Project resolution by path queries `project_paths` instead of `root_path`.

## Schema

New table:

```sql
CREATE TABLE IF NOT EXISTS project_paths (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    path TEXT NOT NULL,
    UNIQUE(project_id, path)
);
```

**Migration:** In `init_db()`, after creating the table, migrate existing `root_path` values into `project_paths`:

```python
# Migrate existing root_path values to project_paths
projects = conn.execute("SELECT id, root_path FROM projects").fetchall()
for p in projects:
    conn.execute(
        "INSERT OR IGNORE INTO project_paths (project_id, path) VALUES (?, ?)",
        (p["id"], p["root_path"]),
    )
```

This is idempotent — running it multiple times is safe due to `INSERT OR IGNORE` and the `UNIQUE` constraint.

## db.py Changes

### New functions

- `add_project_path(project_id, path)` — INSERT OR IGNORE into `project_paths`. Normalizes path via `os.path.abspath()`.
- `remove_project_path(project_id, path)` — DELETE from `project_paths`. Refuses to remove if it's the last remaining path (raises `ValueError`).
- `list_project_paths(project_id)` — SELECT all paths for a project, returns `list[str]`.
- `get_project_by_path(path)` — **modified** to query `project_paths` instead of `projects.root_path`:

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

### Modified functions

- `list_projects()` — for each project, also fetch its paths and include as `paths: list[str]` in the returned dict.
- `upsert_project()` — after upserting, also ensure `root_path` is in `project_paths` (INSERT OR IGNORE).

## api.py Changes

### New endpoints

- `POST /api/projects/{project_id}/paths` — add a path to a project. Body: `{"path": "/path/to/repo"}`. Returns the updated project.
- `DELETE /api/projects/{project_id}/paths` — remove a path. Query param: `?path=/path/to/repo`. Returns 204. Returns 400 if trying to remove the last path.
- `GET /api/projects/{project_id}/paths` — list all paths for a project. Returns `string[]`.

### Modified endpoints

- `GET /api/projects/{id}` — response includes `paths: string[]` field.
- `GET /api/projects` — each project in the list includes `paths: string[]`.

## main.py (MCP) Changes

### New tool: `rag_add_project_path`

```json
{
  "name": "rag_add_project_path",
  "description": "Associate an additional filesystem path with an existing project. Useful for multi-repo projects (e.g., separate frontend and backend repos).",
  "inputSchema": {
    "properties": {
      "project_id": { "type": "string", "description": "Project ID" },
      "path": { "type": "string", "description": "Filesystem path to associate" }
    },
    "required": ["project_id", "path"]
  }
}
```

Handler calls `db.add_project_path(project_id, path)` and returns a confirmation message.

### Modified resolution functions

- `_project_id_from_path(root_path)` — uses `db.get_project_by_path()` which now queries `project_paths`.
- `_ensure_project(args)` — when resolving by path, uses the new `get_project_by_path`.
- `_resolve_project_id(args)` — same change.

### Tool listing

The `rag_list_projects` tool output includes the paths for each project.

## Frontend Changes

### api.ts

- `Project` interface gains `paths: string[]` field.
- New API methods: `addProjectPath(projectId, path)`, `removeProjectPath(projectId, path)`.

### Projects.tsx

The create/edit dialog gains an **"Additional Paths"** section:
- An input field with an "Add" button
- A list of added paths, each with a "Remove" button
- The primary `root_path` field remains separate and required
- On edit, existing additional paths are loaded and displayed

In the projects table, the "Path" column shows the first path. If there are more, a badge with `+N` appears next to it.

## Data Flow

### AI resolves project from path
```
AI in /Users/freis/Projects/refresh/frontend
  → main.py _resolve_project_id(args)
  → _project_id_from_path("/Users/freis/Projects/refresh/frontend")
  → db.get_project_by_path("/Users/freis/Projects/refresh/frontend")
    → SELECT p.* FROM projects p JOIN project_paths pp ON pp.project_id = p.id WHERE pp.path = ?
  → Returns project "refresh" (same project as backend)
  → AI can now search/store in the same knowledge base
```

### AI adds a new path via MCP
```
AI calls rag_add_project_path(project_id="refresh", path="/Users/freis/Projects/refresh/frontend")
  → db.add_project_path("refresh", "/Users/freis/Projects/refresh/frontend")
  → INSERT OR IGNORE INTO project_paths (project_id, path) VALUES (?, ?)
  → Returns "Path added to project 'refresh'"
```

### User manages paths via admin panel
```
User opens Projects page → edits a project → sees Additional Paths section
  → Adds "/Users/freis/Projects/refresh/frontend"
  → POST /api/projects/refresh/paths {"path": "/Users/freis/Projects/refresh/frontend"}
  → db.add_project_path("refresh", "/Users/freis/Projects/refresh/frontend")
  → Project updated with new path
```

## What Does NOT Change

- `root_path` column stays in `projects` table (backward compatibility, used as primary path)
- `upsert_project()` signature stays the same (still takes `root_path`)
- Knowledge entries, tags, embeddings — no changes
- Search functionality — no changes
- Docker config — no changes

## Edge Cases

- **Removing last path:** `remove_project_path` raises `ValueError` if only one path remains. The API returns 400.
- **Path normalization:** All paths are normalized with `os.path.abspath()` before storage and lookup.
- **Duplicate paths:** `INSERT OR IGNORE` + `UNIQUE` constraint prevents duplicates.
- **Migration on existing DB:** The migration runs in `init_db()` every time — `INSERT OR IGNORE` makes it safe to run repeatedly.
