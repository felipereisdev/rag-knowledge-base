"""FastAPI REST API for the RAG admin panel."""
from __future__ import annotations
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
