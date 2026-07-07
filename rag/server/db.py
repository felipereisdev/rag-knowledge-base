"""SQLite database layer for knowledge base RAG."""

import json
import os
import time
import uuid
import embeddings

try:
    from pysqlite3 import dbapi2 as sqlite3
except ImportError:
    import sqlite3

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
    try:
        import sqlite_vec
        try:
            sqlite_vec.load(conn)
        except Exception:
            conn.enable_load_extension(True)
            conn.load_extension(sqlite_vec.loadable_path())
    except Exception:
        pass
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
        dim = embeddings.EMBEDDING_DIM
        conn.execute(f"""
            CREATE VIRTUAL TABLE IF NOT EXISTS entry_embeddings USING vec0(
                entry_id TEXT PRIMARY KEY,
                embedding FLOAT[{dim}]
            )
        """)
        conn.commit()

        # Migration: add language column if missing (for existing DBs)
        cols = [r[1] for r in conn.execute("PRAGMA table_info(projects)").fetchall()]
        if "language" not in cols:
            conn.execute("ALTER TABLE projects ADD COLUMN language TEXT DEFAULT 'en'")
            conn.commit()

        conn.execute("""
            CREATE TABLE IF NOT EXISTS project_paths (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                path TEXT NOT NULL,
                UNIQUE(project_id, path)
            )
        """)

        # Migrate existing root_path values to project_paths
        projects = conn.execute("SELECT id, root_path FROM projects").fetchall()
        for p in projects:
            conn.execute(
                "INSERT OR IGNORE INTO project_paths (project_id, path) VALUES (?, ?)",
                (p["id"], p["root_path"]),
            )
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
        conn.execute(
            "INSERT OR IGNORE INTO project_paths (project_id, path) VALUES (?, ?)",
            (project_id, os.path.abspath(root_path)),
        )
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


def list_entries(project_id=None, category=None, tags=None, status=None, limit=500):
    """List knowledge entries with optional filters."""
    conn = get_connection()
    try:
        query = "SELECT e.* FROM knowledge_entries e"
        params = []
        conditions = []
        if project_id:
            conditions.append("e.project_id = ?")
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

        if conditions:
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


# ---- Vector embedding operations ----

def store_embedding(entry_id, embedding):
    """Store or replace an entry's embedding."""
    import struct
    blob = struct.pack(f"{len(embedding)}f", *embedding)
    conn = get_connection()
    try:
        conn.execute("DELETE FROM entry_embeddings WHERE entry_id = ?", (entry_id,))
        conn.execute(
            "INSERT INTO entry_embeddings (entry_id, embedding) VALUES (?, ?)",
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


def search_entries_by_embedding(query_embedding, project_id=None, k=10, category=None, tags=None):
    """Search entries by embedding similarity with project/category/tag filtering."""
    neighbors = search_embeddings(query_embedding, k=k)
    if not neighbors:
        return []
    entry_ids = [n["entry_id"] for n in neighbors]
    placeholders = ",".join("?" for _ in entry_ids)
    conn = get_connection()
    try:
        conditions = [f"e.entry_id IN ({placeholders})"]
        params = list(entry_ids)
        if project_id:
            conditions.append("ke.project_id = ?")
            params.append(project_id)
        sql = f"""
            SELECT ke.*, e.entry_id
            FROM entry_embeddings e
            JOIN knowledge_entries ke ON ke.id = e.entry_id
            WHERE {" AND ".join(conditions)}
        """
        if category:
            sql += " AND ke.category = ?"
            params.append(category)
        if tags:
            for tag in tags:
                if project_id:
                    sql += " AND e.entry_id IN (SELECT et.entry_id FROM entry_tags et JOIN tags t ON t.id = et.tag_id WHERE t.name = ? AND t.project_id = ?)"
                    params.extend([tag.lower(), project_id])
                else:
                    sql += " AND e.entry_id IN (SELECT et.entry_id FROM entry_tags et JOIN tags t ON t.id = et.tag_id WHERE t.name = ?)"
                    params.append(tag.lower())
        sql += " ORDER BY CASE e.entry_id"
        for i, eid in enumerate(entry_ids):
            sql += f" WHEN ? THEN {i}"
            params.append(eid)
        sql += " END"
        rows = conn.execute(sql, params).fetchall()
        score_map = {n["entry_id"]: round(1.0 / (1.0 + n["distance"]), 4) for n in neighbors}
        results = []
        for r in rows:
            entry = dict(r)
            entry["tags"] = get_tags_for_entry(entry["entry_id"])
            entry["score"] = score_map.get(entry["entry_id"], 0)
            results.append(entry)
        return results
    finally:
        conn.close()
