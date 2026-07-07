"""FastAPI REST API for the RAG admin panel."""
from __future__ import annotations
import threading
import uvicorn
from fastapi import FastAPI, HTTPException, Query
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel

import db
import embeddings

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
    root_path: str | None = None
    paths: list[str] | None = None
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


class PathCreate(BaseModel):
    path: str


# ---- Project endpoints ----

@app.get("/api/projects")
def list_projects():
    return db.list_projects()


@app.post("/api/projects", status_code=201)
def create_project(proj: ProjectCreate):
    root = proj.root_path or (proj.paths[0] if proj.paths else None)
    if not root:
        raise HTTPException(400, "root_path or paths is required")
    db.upsert_project(
        proj.id, proj.name, root,
        proj.description, proj.project_type, proj.language,
        paths=proj.paths,
    )
    return db.get_project(proj.id)


@app.get("/api/projects/{project_id}")
def get_project(project_id: str):
    proj = db.get_project(project_id)
    if not proj:
        raise HTTPException(404, "Project not found")
    proj["paths"] = db.list_project_paths(project_id)
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
        for eid in entry_ids:
            entry_approved = db.get_entry(eid)
            if entry_approved:
                vec = embeddings.embed_text(entry_approved["title"] + " " + entry_approved["content"])
                db.store_embedding(eid, vec)
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
    proj = db.get_project(project_id)
    proj["paths"] = db.list_project_paths(project_id)
    return proj


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


# ---- Entry endpoints ----

@app.get("/api/entries")
def list_entries(
    project_id: str | None = Query(default=None),
    category: str | None = None,
    tags: list[str] | None = Query(default=None),
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
    entry_new = db.get_entry(entry_id)
    if entry_new["status"] == "indexed":
        vec = embeddings.embed_text(entry_new["title"] + " " + entry_new["content"])
        db.store_embedding(entry_id, vec)
    return db.get_entry(entry_id)


@app.delete("/api/entries/{entry_id}", status_code=204)
def delete_entry(entry_id: str):
    entry = db.get_entry(entry_id)
    if not entry:
        raise HTTPException(404, "Entry not found")
    db.delete_embedding(entry_id)
    db.remove_entry(entry_id)
    return None


@app.post("/api/entries/{entry_id}/approve")
def approve_entry(entry_id: str):
    entry = db.get_entry(entry_id)
    if not entry:
        raise HTTPException(404, "Entry not found")
    db.approve_entries([entry_id])
    vec = embeddings.embed_text(entry["title"] + " " + entry["content"])
    db.store_embedding(entry_id, vec)
    return {"ok": True}


@app.post("/api/entries/{entry_id}/reject")
def reject_entry(entry_id: str):
    entry = db.get_entry(entry_id)
    if not entry:
        raise HTTPException(404, "Entry not found")
    db.reject_entries([entry_id])
    return {"ok": True}


# ---- Search and tags ----

@app.get("/api/search")
def search(
    q: str = Query(...),
    project_id: str | None = Query(default=None),
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


@app.get("/api/tags")
def list_tags(project_id: str = Query(...)):
    return db.get_all_tags(project_id)


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
