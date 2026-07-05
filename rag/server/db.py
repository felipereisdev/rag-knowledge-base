"""SQLite database layer for per-project RAG knowledge base."""

import json
import os
import sqlite3
import time
import hashlib

DATA_DIR = os.path.expanduser("~/.rag")
DB_PATH = os.path.join(DATA_DIR, "knowledge.db")


def get_connection():
    os.makedirs(DATA_DIR, exist_ok=True)
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute("PRAGMA foreign_keys=ON")
    return conn


def init_db():
    conn = get_connection()
    try:
        conn.executescript("""
            CREATE TABLE IF NOT EXISTS projects (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                root_path TEXT NOT NULL,
                description TEXT DEFAULT '',
                project_type TEXT DEFAULT '',
                created_at REAL NOT NULL,
                updated_at REAL NOT NULL
            );

            CREATE TABLE IF NOT EXISTS documents (
                id TEXT PRIMARY KEY,
                project_id TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                rel_path TEXT NOT NULL,
                file_type TEXT DEFAULT 'source',
                file_hash TEXT NOT NULL,
                indexed_at REAL NOT NULL,
                UNIQUE(project_id, rel_path)
            );

            CREATE TABLE IF NOT EXISTS chunks (
                id TEXT PRIMARY KEY,
                project_id TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                document_id TEXT REFERENCES documents(id) ON DELETE CASCADE,
                chunk_index INTEGER NOT NULL,
                content TEXT NOT NULL,
                file_type TEXT DEFAULT 'source',
                source TEXT DEFAULT 'file',
                status TEXT NOT NULL DEFAULT 'pending',
                created_at REAL NOT NULL,
                UNIQUE(document_id, chunk_index)
            );

            CREATE INDEX IF NOT EXISTS idx_chunks_project_status
                ON chunks(project_id, status);
            CREATE INDEX IF NOT EXISTS idx_chunks_status
                ON chunks(status);
            CREATE INDEX IF NOT EXISTS idx_documents_project
                ON documents(project_id);
        """)

        # Migrations for existing DBs
        cols = [r[1] for r in conn.execute("PRAGMA table_info(chunks)").fetchall()]
        if "file_type" not in cols:
            conn.execute("ALTER TABLE chunks ADD COLUMN file_type TEXT DEFAULT 'source'")
        if "source" not in cols:
            conn.execute("ALTER TABLE chunks ADD COLUMN source TEXT DEFAULT 'file'")

        cols_doc = [r[1] for r in conn.execute("PRAGMA table_info(documents)").fetchall()]
        if "file_type" not in cols_doc:
            conn.execute("ALTER TABLE documents ADD COLUMN file_type TEXT DEFAULT 'source'")

        cols_proj = [r[1] for r in conn.execute("PRAGMA table_info(projects)").fetchall()]
        if "project_type" not in cols_proj:
            conn.execute("ALTER TABLE projects ADD COLUMN project_type TEXT DEFAULT ''")

        conn.commit()
    finally:
        conn.close()


# ---- Project operations ----

def upsert_project(project_id, name, root_path, description="", project_type=""):
    conn = get_connection()
    try:
        now = time.time()
        conn.execute("""
            INSERT INTO projects (id, name, root_path, description, project_type, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(id) DO UPDATE SET
                name=excluded.name,
                root_path=excluded.root_path,
                description=excluded.description,
                project_type=excluded.project_type,
                updated_at=excluded.updated_at
        """, (project_id, name, root_path, description, project_type, now, now))
        conn.commit()
    finally:
        conn.close()


def get_project(project_id):
    conn = get_connection()
    try:
        row = conn.execute("SELECT * FROM projects WHERE id = ?", (project_id,)).fetchone()
        return dict(row) if row else None
    finally:
        conn.close()


def get_project_by_path(root_path):
    """Find a project by its root path."""
    conn = get_connection()
    try:
        row = conn.execute("SELECT * FROM projects WHERE root_path = ?", (root_path,)).fetchone()
        return dict(row) if row else None
    finally:
        conn.close()


def list_projects():
    conn = get_connection()
    try:
        rows = conn.execute("""
            SELECT p.*,
                   (SELECT COUNT(*) FROM chunks c WHERE c.project_id = p.id AND c.status = 'indexed') as indexed_count,
                   (SELECT COUNT(*) FROM chunks c WHERE c.project_id = p.id AND c.status = 'pending') as pending_count
            FROM projects p ORDER BY p.updated_at DESC
        """).fetchall()
        return [dict(r) for r in rows]
    finally:
        conn.close()


# ---- Document operations ----

def compute_file_hash(filepath):
    h = hashlib.sha256()
    with open(filepath, "rb") as f:
        for chunk in iter(lambda: f.read(8192), b""):
            h.update(chunk)
    return h.hexdigest()


def get_document(project_id, rel_path):
    conn = get_connection()
    try:
        row = conn.execute(
            "SELECT * FROM documents WHERE project_id = ? AND rel_path = ?",
            (project_id, rel_path)
        ).fetchone()
        return dict(row) if row else None
    finally:
        conn.close()


def get_documents_by_project(project_id):
    conn = get_connection()
    try:
        rows = conn.execute(
            "SELECT * FROM documents WHERE project_id = ? ORDER BY rel_path",
            (project_id,)
        ).fetchall()
        return [dict(r) for r in rows]
    finally:
        conn.close()


def upsert_document(project_id, rel_path, file_hash, file_type="source"):
    conn = get_connection()
    try:
        doc_id = f"{project_id}::{rel_path}"
        now = time.time()
        conn.execute("""
            INSERT INTO documents (id, project_id, rel_path, file_type, file_hash, indexed_at)
            VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT(project_id, rel_path) DO UPDATE SET
                file_type=excluded.file_type,
                file_hash=excluded.file_hash,
                indexed_at=excluded.indexed_at
        """, (doc_id, project_id, rel_path, file_type, file_hash, now))
        conn.commit()
        return doc_id
    finally:
        conn.close()


def remove_document(doc_id):
    conn = get_connection()
    try:
        conn.execute("DELETE FROM chunks WHERE document_id = ?", (doc_id,))
        conn.execute("DELETE FROM documents WHERE id = ?", (doc_id,))
        conn.commit()
    finally:
        conn.close()


# ---- Chunk operations ----

def insert_chunks(project_id, doc_id, chunks_data, file_type="source", source="file"):
    conn = get_connection()
    try:
        now = time.time()
        rows = []
        for i, content in enumerate(chunks_data):
            chunk_id = f"{doc_id}::chunk::{i}"
            rows.append((chunk_id, project_id, doc_id, i, content, file_type, source, "pending", now))
        conn.executemany("""
            INSERT OR IGNORE INTO chunks (id, project_id, document_id, chunk_index, content, file_type, source, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        """, rows)
        conn.commit()
        return len(rows)
    finally:
        conn.close()


def insert_knowledge_chunk(project_id, title, content, source="knowledge"):
    """Insert a model-generated knowledge entry (business rules, design decisions, etc.).

    These are stored as chunks with source='knowledge' and go through the same
    approval workflow as file-based chunks.
    """
    conn = get_connection()
    try:
        now = time.time()
        # Use a virtual document for knowledge entries
        doc_id = f"{project_id}::__knowledge__"
        # Ensure the virtual document exists
        conn.execute("""
            INSERT OR IGNORE INTO documents (id, project_id, rel_path, file_type, file_hash, indexed_at)
            VALUES (?, ?, ?, 'knowledge', '', ?)
        """, (doc_id, project_id, f"[knowledge] {title}", now))

        # Find next chunk index
        row = conn.execute(
            "SELECT MAX(chunk_index) as max_idx FROM chunks WHERE document_id = ?",
            (doc_id,)
        ).fetchone()
        next_idx = (row["max_idx"] or -1) + 1

        chunk_id = f"{doc_id}::chunk::{next_idx}::{int(now*1000)}"
        conn.execute("""
            INSERT INTO chunks (id, project_id, document_id, chunk_index, content, file_type, source, status, created_at)
            VALUES (?, ?, ?, ?, ?, 'knowledge', ?, 'pending', ?)
        """, (chunk_id, project_id, doc_id, next_idx, content, source, now))
        conn.commit()
        return chunk_id
    finally:
        conn.close()


def get_pending_chunks(project_id=None):
    conn = get_connection()
    try:
        if project_id:
            rows = conn.execute("""
                SELECT c.*, d.rel_path
                FROM chunks c
                JOIN documents d ON c.document_id = d.id
                WHERE c.status = 'pending' AND c.project_id = ?
                ORDER BY d.rel_path, c.chunk_index
            """, (project_id,)).fetchall()
        else:
            rows = conn.execute("""
                SELECT c.*, d.rel_path
                FROM chunks c
                JOIN documents d ON c.document_id = d.id
                WHERE c.status = 'pending'
                ORDER BY d.rel_path, c.chunk_index
            """).fetchall()
        return [dict(r) for r in rows]
    finally:
        conn.close()


def approve_chunks(chunk_ids):
    conn = get_connection()
    try:
        if chunk_ids == ["__ALL__"]:
            conn.execute("UPDATE chunks SET status = 'indexed' WHERE status = 'pending'")
        else:
            placeholders = ",".join("?" for _ in chunk_ids)
            conn.execute(
                f"UPDATE chunks SET status = 'indexed' WHERE id IN ({placeholders})",
                chunk_ids
            )
        conn.commit()
    finally:
        conn.close()


def reject_chunks(chunk_ids):
    conn = get_connection()
    try:
        if chunk_ids == ["__ALL__"]:
            conn.execute("UPDATE chunks SET status = 'rejected' WHERE status = 'pending'")
        else:
            placeholders = ",".join("?" for _ in chunk_ids)
            conn.execute(
                f"UPDATE chunks SET status = 'rejected' WHERE id IN ({placeholders})",
                chunk_ids
            )
        conn.commit()
    finally:
        conn.close()


def get_indexed_chunks(project_id, limit=5000):
    conn = get_connection()
    try:
        rows = conn.execute("""
            SELECT c.*, d.rel_path
            FROM chunks c
            JOIN documents d ON c.document_id = d.id
            WHERE c.status = 'indexed' AND c.project_id = ?
            ORDER BY c.created_at DESC
            LIMIT ?
        """, (project_id, limit)).fetchall()
        return [dict(r) for r in rows]
    finally:
        conn.close()


def remove_document_by_path(project_id, rel_path):
    doc = get_document(project_id, rel_path)
    if doc:
        remove_document(doc["id"])


def get_project_stats(project_id):
    conn = get_connection()
    try:
        row = conn.execute("""
            SELECT
                (SELECT COUNT(*) FROM chunks WHERE project_id = ? AND status = 'indexed') as indexed,
                (SELECT COUNT(*) FROM chunks WHERE project_id = ? AND status = 'pending') as pending,
                (SELECT COUNT(*) FROM chunks WHERE project_id = ? AND status = 'rejected') as rejected,
                (SELECT COUNT(*) FROM documents WHERE project_id = ?) as documents
        """, (project_id, project_id, project_id, project_id)).fetchone()
        return dict(row) if row else {"indexed": 0, "pending": 0, "rejected": 0, "documents": 0}
    finally:
        conn.close()
