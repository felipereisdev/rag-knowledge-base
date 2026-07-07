"""FastAPI REST API for the RAG admin panel."""
from __future__ import annotations
import os
import threading
import uvicorn
from fastapi import FastAPI, HTTPException, Query
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel

import db
import embeddings
import indexing

app = FastAPI(title="RAG Admin API", version="0.4.0")


def search_min_score():
    """Minimum cosine similarity (1 - cosine distance) for a search hit to count
    as a real match rather than nearest-neighbor noise. Configurable via
    RAG_SEARCH_MIN_SCORE. Calibrated defaults: relevant matches score well above
    0.30 for both all-MiniLM-L6-v2 (test model) and
    paraphrase-multilingual-mpnet-base-v2 (production default), while unrelated
    queries land below it.
    """
    try:
        return float(os.environ.get("RAG_SEARCH_MIN_SCORE", "0.30"))
    except ValueError:
        return 0.30

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


class EntityIn(BaseModel):
    name: str
    type: str = ""


class RelationIn(BaseModel):
    subject: str
    predicate: str
    object: str


class EntryCreate(BaseModel):
    project_id: str
    title: str
    content: str
    category: str = "insight"
    tags: list[str] = []
    entities: list[EntityIn] = []
    relations: list[RelationIn] = []


class EntryUpdate(BaseModel):
    title: str | None = None
    content: str | None = None
    category: str | None = None
    tags: list[str] | None = None
    entities: list[EntityIn] | None = None
    relations: list[RelationIn] | None = None


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
    db.remove_project(project_id)
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
                indexing.index_entry(entry_approved)
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

def _persist_graph_data(project_id, entry_id, entities, relations):
    """Upsert entities/relations for an entry (same shape as the MCP tool)."""
    for ent in entities or []:
        entity_id = db.upsert_entity(project_id, ent.name, ent.type)
        db.link_entry_entity(entry_id, entity_id)
    for rel in relations or []:
        db.add_relation(project_id, rel.subject, rel.predicate, rel.object, entry_id=entry_id)


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
    try:
        entry_id = db.store_knowledge_entry(
            project_id=entry.project_id,
            title=entry.title,
            content=entry.content,
            category=entry.category,
            source="manual",
            tags=entry.tags,
        )
    except db.sqlite3.IntegrityError:
        raise HTTPException(409, "An entry with this title already exists in this project")
    _persist_graph_data(entry.project_id, entry_id, entry.entities, entry.relations)
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
    try:
        db.update_entry(
            entry_id,
            title=update.title,
            content=update.content,
            category=update.category,
            tags=update.tags,
        )
    except db.sqlite3.IntegrityError:
        raise HTTPException(409, "An entry with this title already exists in this project")
    if update.relations is not None:
        conn = db.get_connection()
        try:
            conn.execute("DELETE FROM relations WHERE entry_id = ?", (entry_id,))
            conn.execute("DELETE FROM entry_entities WHERE entry_id = ?", (entry_id,))
            conn.commit()
        finally:
            conn.close()
    if update.entities is not None or update.relations is not None:
        _persist_graph_data(entry["project_id"], entry_id, update.entities, update.relations)
    entry_new = db.get_entry(entry_id)
    if entry_new["status"] == "indexed":
        indexing.index_entry(entry_new)
    return db.get_entry(entry_id)


@app.delete("/api/entries/{entry_id}", status_code=204)
def delete_entry(entry_id: str):
    entry = db.get_entry(entry_id)
    if not entry:
        raise HTTPException(404, "Entry not found")
    indexing.unindex_entry(entry_id)
    db.remove_entry(entry_id)
    return None


@app.post("/api/entries/{entry_id}/approve")
def approve_entry(entry_id: str):
    entry = db.get_entry(entry_id)
    if not entry:
        raise HTTPException(404, "Entry not found")
    db.approve_entries([entry_id])
    indexing.index_entry(db.get_entry(entry_id))
    return {"ok": True}


@app.post("/api/entries/{entry_id}/reject")
def reject_entry(entry_id: str):
    entry = db.get_entry(entry_id)
    if not entry:
        raise HTTPException(404, "Entry not found")
    db.reject_entries([entry_id])
    return {"ok": True}


@app.get("/api/entries/{entry_id}/graph")
def get_entry_graph(entry_id: str):
    entry = db.get_entry(entry_id)
    if not entry:
        raise HTTPException(404, "Entry not found")
    return {
        "entities": db.get_entities_for_entry(entry_id),
        "relations": db.get_relations_for_entry(entry_id),
        "links": db.get_entry_links(entry_id),
    }


# ---- Knowledge graph ----

@app.get("/api/graph")
def get_graph(project_id: str = Query(...)):
    proj = db.get_project(project_id)
    if not proj:
        raise HTTPException(404, "Project not found")
    return db.get_graph(project_id)


@app.get("/api/graph/entity")
def get_entity_graph(
    project_id: str = Query(...),
    name: str = Query(...),
    depth: int = 1,
):
    proj = db.get_project(project_id)
    if not proj:
        raise HTTPException(404, "Project not found")
    return db.query_entity_graph(project_id, name, depth=depth)


# ---- Search and tags ----

@app.get("/api/search")
def search(
    q: str = Query(...),
    project_id: str | None = Query(default=None),
    category: str | None = None,
    tags: list[str] | None = Query(None),
    top_k: int = 5,
    expand: bool = False,
    depth: int = 1,
):
    if not q.strip():
        if expand:
            return {"results": [], "graph": {"triples": [], "related_entries": []}}
        return []
    query_vec = embeddings.embed_query(q)
    results = db.hybrid_search(
        q, query_vec,
        project_id=project_id, k=top_k, category=category, tags=tags,
        min_score=search_min_score(),
    )
    if not expand:
        return results
    graph = {"triples": [], "related_entries": []}
    if project_id and results:
        seed_ids = [r["id"] for r in results]
        graph = db.expand_entries_via_graph(project_id, seed_ids, depth=depth, limit=5)
    return {"results": results, "graph": graph}


@app.get("/api/tags")
def list_tags(project_id: str = Query(...)):
    return db.get_all_tags(project_id)


# ---- Shared embedding endpoint ----

class EmbedRequest(BaseModel):
    texts: list[str]


@app.post("/api/embed")
def embed_texts(req: EmbedRequest):
    return {
        "model": embeddings.MODEL_NAME,
        "dim": embeddings.EMBEDDING_DIM,
        "embeddings": [embeddings.embed_local(t) for t in req.texts],
    }


# ---- Server startup ----

_server_thread = None
_server_port = None


def start_api_server(port=8000):
    """Start uvicorn in a daemon thread. Returns the port."""
    embeddings.serving_locally = True
    global _server_thread, _server_port
    if _server_thread is not None:
        return _server_port
    config = uvicorn.Config(app, host="0.0.0.0", port=port, log_level="warning")
    server = uvicorn.Server(config)
    _server_thread = threading.Thread(target=server.run, daemon=True)
    _server_thread.start()
    _server_port = port
    return _server_port
