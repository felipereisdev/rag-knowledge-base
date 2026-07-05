"""SQLite database layer for knowledge base RAG."""

import json
import os
import sqlite3
import time
import uuid

DATA_DIR = os.path.expanduser("~/.rag")
DB_PATH = os.path.join(DATA_DIR, "knowledge.db")

VALID_CATEGORIES = {
    "business-rule", "design-decision", "architecture",
    "documentation", "insight", "convention", "constraint",
}


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
                language TEXT DEFAULT 'en',
                created_at REAL NOT NULL,
                updated_at REAL NOT NULL
            );

            CREATE TABLE IF NOT EXISTS knowledge_entries (
                id TEXT PRIMARY KEY,
                project_id TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                title TEXT NOT NULL,
                content TEXT NOT NULL DEFAULT '',
                category TEXT NOT NULL DEFAULT 'insight',
                source TEXT NOT NULL DEFAULT 'manual',
                author TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL DEFAULT 'pending',
                metadata TEXT NOT NULL DEFAULT '{}',
                created_at REAL NOT NULL,
                updated_at REAL NOT NULL,
                UNIQUE(project_id, title)
            );

            CREATE TABLE IF NOT EXISTS tags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                name TEXT NOT NULL,
                UNIQUE(project_id, name)
            );

            CREATE TABLE IF NOT EXISTS entry_tags (
                entry_id TEXT NOT NULL REFERENCES knowledge_entries(id) ON DELETE CASCADE,
                tag_id INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
                PRIMARY KEY(entry_id, tag_id)
            );

            CREATE INDEX IF NOT EXISTS idx_knowledge_entries_project
                ON knowledge_entries(project_id);
            CREATE INDEX IF NOT EXISTS idx_knowledge_entries_status
                ON knowledge_entries(status);
            CREATE INDEX IF NOT EXISTS idx_knowledge_entries_category
                ON knowledge_entries(category);
            CREATE INDEX IF NOT EXISTS idx_tags_project
                ON tags(project_id);
            CREATE INDEX IF NOT EXISTS idx_entry_tags_entry
                ON entry_tags(entry_id);
        """)
        conn.commit()

        # Migration: add language column if missing (for existing DBs)
        cols = [r[1] for r in conn.execute("PRAGMA table_info(projects)").fetchall()]
        if "language" not in cols:
            conn.execute("ALTER TABLE projects ADD COLUMN language TEXT DEFAULT 'en'")
            conn.commit()
    finally:
        conn.close()


def upsert_project(project_id, name, root_path, description="", project_type="", language=""):
    conn = get_connection()
    try:
        now = time.time()
        if language:
            conn.execute("""
                INSERT INTO projects (id, name, root_path, description, project_type, language, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(id) DO UPDATE SET
                    name=excluded.name,
                    root_path=excluded.root_path,
                    description=excluded.description,
                    project_type=excluded.project_type,
                    language=excluded.language,
                    updated_at=excluded.updated_at
            """, (project_id, name, root_path, description, project_type, language, now, now))
        else:
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
                   (SELECT COUNT(*) FROM knowledge_entries e WHERE e.project_id = p.id AND e.status = 'indexed') as indexed_count,
                   (SELECT COUNT(*) FROM knowledge_entries e WHERE e.project_id = p.id AND e.status = 'pending') as pending_count
            FROM projects p ORDER BY p.updated_at DESC
        """).fetchall()
        return [dict(r) for r in rows]
    finally:
        conn.close()


# ---- Knowledge entry operations ----


def store_knowledge_entry(project_id, title, content, category="insight",
                           source="manual", author="", tags=None, metadata=None):
    """Store a knowledge entry. Returns the entry ID."""
    if category not in VALID_CATEGORIES:
        category = "insight"
    conn = get_connection()
    try:
        now = time.time()
        entry_id = str(uuid.uuid4())
        meta_json = json.dumps(metadata or {})
        conn.execute("""
            INSERT INTO knowledge_entries
                (id, project_id, title, content, category, source, author, status, metadata, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)
        """, (entry_id, project_id, title, content, category, source, author, meta_json, now, now))

        if tags:
            _add_tags_internal(conn, entry_id, project_id, tags)

        conn.commit()
        return entry_id
    finally:
        conn.close()


def _add_tags_internal(conn, entry_id, project_id, tags):
    """Add tags to an entry. Called within an existing connection."""
    for tag_name in tags:
        tag_name = tag_name.strip().lower()
        if not tag_name:
            continue
        conn.execute("""
            INSERT OR IGNORE INTO tags (project_id, name) VALUES (?, ?)
        """, (project_id, tag_name))
        row = conn.execute(
            "SELECT id FROM tags WHERE project_id = ? AND name = ?",
            (project_id, tag_name)
        ).fetchone()
        if row:
            conn.execute("INSERT OR IGNORE INTO entry_tags (entry_id, tag_id) VALUES (?, ?)",
                         (entry_id, row["id"]))


def get_entry(entry_id):
    conn = get_connection()
    try:
        row = conn.execute("SELECT * FROM knowledge_entries WHERE id = ?", (entry_id,)).fetchone()
        if not row:
            return None
        entry = dict(row)
        entry["tags"] = get_tags_for_entry(entry_id)
        entry["metadata"] = json.loads(entry.get("metadata") or "{}")
        return entry
    finally:
        conn.close()


def list_entries(project_id, category=None, tags=None, status=None, limit=500):
    """List knowledge entries with optional filters."""
    conn = get_connection()
    try:
        query = "SELECT e.* FROM knowledge_entries e"
        params = []
        conditions = ["e.project_id = ?"]
        params.append(project_id)

        if category:
            conditions.append("e.category = ?")
            params.append(category)

        if status:
            conditions.append("e.status = ?")
            params.append(status)

        having = None
        if tags:
            placeholders = ",".join("?" for _ in tags)
            query += " JOIN entry_tags et ON et.entry_id = e.id JOIN tags t ON t.id = et.tag_id"
            conditions.append(f"t.name IN ({placeholders})")
            params.extend([t.lower() for t in tags])
            if len(tags) > 1:
                having = f"COUNT(DISTINCT t.name) = {len(tags)}"

        query += " WHERE " + " AND ".join(conditions)
        if tags:
            query += " GROUP BY e.id"
            if having:
                query += " HAVING " + having
        query += " ORDER BY e.created_at DESC LIMIT ?"
        params.append(limit)

        rows = conn.execute(query, params).fetchall()
        entries = []
        for r in rows:
            entry = dict(r)
            entry["tags"] = get_tags_for_entry(entry["id"])
            entry["metadata"] = json.loads(entry.get("metadata") or "{}")
            entries.append(entry)
        return entries
    finally:
        conn.close()


def update_entry(entry_id, title=None, content=None, category=None, tags=None):
    """Update a knowledge entry. Only updates provided fields."""
    conn = get_connection()
    try:
        updates = []
        params = []
        if title is not None:
            updates.append("title = ?")
            params.append(title)
        if content is not None:
            updates.append("content = ?")
            params.append(content)
        if category is not None:
            if category not in VALID_CATEGORIES:
                category = "insight"
            updates.append("category = ?")
            params.append(category)

        if updates:
            updates.append("updated_at = ?")
            params.append(time.time())
            params.append(entry_id)
            conn.execute(f"UPDATE knowledge_entries SET {', '.join(updates)} WHERE id = ?", params)

        if tags is not None:
            entry = conn.execute("SELECT project_id FROM knowledge_entries WHERE id = ?", (entry_id,)).fetchone()
            if entry:
                conn.execute("DELETE FROM entry_tags WHERE entry_id = ?", (entry_id,))
                _add_tags_internal(conn, entry_id, entry["project_id"], tags)

        conn.commit()
    finally:
        conn.close()


def remove_entry(entry_id):
    conn = get_connection()
    try:
        conn.execute("DELETE FROM knowledge_entries WHERE id = ?", (entry_id,))
        conn.commit()
    finally:
        conn.close()


def get_tags_for_entry(entry_id):
    conn = get_connection()
    try:
        rows = conn.execute("""
            SELECT t.name FROM tags t
            JOIN entry_tags et ON et.tag_id = t.id
            WHERE et.entry_id = ?
            ORDER BY t.name
        """, (entry_id,)).fetchall()
        return [r["name"] for r in rows]
    finally:
        conn.close()


def get_all_tags(project_id):
    conn = get_connection()
    try:
        rows = conn.execute(
            "SELECT name FROM tags WHERE project_id = ? ORDER BY name",
            (project_id,)
        ).fetchall()
        return [r["name"] for r in rows]
    finally:
        conn.close()


# ---- Approval workflow ----


def get_pending_entries(project_id=None):
    conn = get_connection()
    try:
        if project_id:
            rows = conn.execute("""
                SELECT * FROM knowledge_entries
                WHERE status = 'pending' AND project_id = ?
                ORDER BY created_at DESC
            """, (project_id,)).fetchall()
        else:
            rows = conn.execute("""
                SELECT * FROM knowledge_entries
                WHERE status = 'pending'
                ORDER BY created_at DESC
            """).fetchall()
        entries = []
        for r in rows:
            entry = dict(r)
            entry["tags"] = get_tags_for_entry(entry["id"])
            entries.append(entry)
        return entries
    finally:
        conn.close()


def approve_entries(entry_ids):
    conn = get_connection()
    try:
        if entry_ids == ["__ALL__"]:
            conn.execute("UPDATE knowledge_entries SET status = 'indexed' WHERE status = 'pending'")
        else:
            placeholders = ",".join("?" for _ in entry_ids)
            conn.execute(
                f"UPDATE knowledge_entries SET status = 'indexed' WHERE id IN ({placeholders})",
                entry_ids
            )
        conn.commit()
    finally:
        conn.close()


def reject_entries(entry_ids):
    conn = get_connection()
    try:
        if entry_ids == ["__ALL__"]:
            conn.execute("UPDATE knowledge_entries SET status = 'rejected' WHERE status = 'pending'")
        else:
            placeholders = ",".join("?" for _ in entry_ids)
            conn.execute(
                f"UPDATE knowledge_entries SET status = 'rejected' WHERE id IN ({placeholders})",
                entry_ids
            )
        conn.commit()
    finally:
        conn.close()


def get_indexed_entries(project_id, limit=5000):
    """Get all approved entries for search index building."""
    conn = get_connection()
    try:
        rows = conn.execute("""
            SELECT * FROM knowledge_entries
            WHERE status = 'indexed' AND project_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        """, (project_id, limit)).fetchall()
        entries = []
        for r in rows:
            entry = dict(r)
            entry["tags"] = get_tags_for_entry(entry["id"])
            entries.append(entry)
        return entries
    finally:
        conn.close()


def get_project_stats(project_id):
    conn = get_connection()
    try:
        row = conn.execute("""
            SELECT
                (SELECT COUNT(*) FROM knowledge_entries WHERE project_id = ? AND status = 'indexed') as indexed,
                (SELECT COUNT(*) FROM knowledge_entries WHERE project_id = ? AND status = 'pending') as pending,
                (SELECT COUNT(*) FROM knowledge_entries WHERE project_id = ? AND status = 'rejected') as rejected,
                (SELECT COUNT(*) FROM knowledge_entries WHERE project_id = ?) as total
        """, (project_id, project_id, project_id, project_id)).fetchone()
        return dict(row) if row else {"indexed": 0, "pending": 0, "rejected": 0, "total": 0}
    finally:
        conn.close()
