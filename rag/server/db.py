"""SQLite database layer for knowledge base RAG."""

import json
import os
import re
import struct
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
    conn.execute("PRAGMA busy_timeout=5000")
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


BASE_SCHEMA = """
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

    CREATE TABLE IF NOT EXISTS project_paths (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
        path TEXT NOT NULL,
        created_at REAL NOT NULL DEFAULT 0,
        UNIQUE(project_id, path)
    );

    CREATE TABLE IF NOT EXISTS entities (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
        name TEXT NOT NULL,
        norm_name TEXT NOT NULL,
        type TEXT NOT NULL DEFAULT '',
        created_at REAL NOT NULL,
        UNIQUE(project_id, norm_name)
    );

    CREATE TABLE IF NOT EXISTS relations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
        subject_id INTEGER NOT NULL REFERENCES entities(id) ON DELETE CASCADE,
        predicate TEXT NOT NULL,
        object_id INTEGER NOT NULL REFERENCES entities(id) ON DELETE CASCADE,
        entry_id TEXT REFERENCES knowledge_entries(id) ON DELETE CASCADE,
        created_at REAL NOT NULL
    );
    CREATE UNIQUE INDEX IF NOT EXISTS idx_relations_unique
        ON relations(project_id, subject_id, predicate, object_id, IFNULL(entry_id, ''));

    CREATE TABLE IF NOT EXISTS entry_entities (
        entry_id TEXT NOT NULL REFERENCES knowledge_entries(id) ON DELETE CASCADE,
        entity_id INTEGER NOT NULL REFERENCES entities(id) ON DELETE CASCADE,
        PRIMARY KEY(entry_id, entity_id)
    );

    CREATE TABLE IF NOT EXISTS entry_links (
        from_entry TEXT NOT NULL REFERENCES knowledge_entries(id) ON DELETE CASCADE,
        to_entry TEXT NOT NULL REFERENCES knowledge_entries(id) ON DELETE CASCADE,
        relation TEXT NOT NULL DEFAULT 'related',
        PRIMARY KEY(from_entry, to_entry, relation)
    );

    CREATE INDEX IF NOT EXISTS idx_knowledge_entries_project ON knowledge_entries(project_id);
    CREATE INDEX IF NOT EXISTS idx_knowledge_entries_status ON knowledge_entries(status);
    CREATE INDEX IF NOT EXISTS idx_knowledge_entries_category ON knowledge_entries(category);
    CREATE INDEX IF NOT EXISTS idx_tags_project ON tags(project_id);
    CREATE INDEX IF NOT EXISTS idx_entry_tags_entry ON entry_tags(entry_id);
    CREATE INDEX IF NOT EXISTS idx_entities_project ON entities(project_id);
    CREATE INDEX IF NOT EXISTS idx_relations_subject ON relations(subject_id);
    CREATE INDEX IF NOT EXISTS idx_relations_object ON relations(object_id);
    CREATE INDEX IF NOT EXISTS idx_relations_entry ON relations(entry_id);
    CREATE INDEX IF NOT EXISTS idx_entry_entities_entity ON entry_entities(entity_id);
"""


def _create_virtual_tables(conn):
    dim = embeddings.EMBEDDING_DIM
    conn.execute(f"""
        CREATE VIRTUAL TABLE IF NOT EXISTS chunk_embeddings USING vec0(
            embedding FLOAT[{dim}] distance_metric=cosine,
            project_id TEXT partition key,
            entry_id TEXT,
            chunk_index INTEGER
        )
    """)
    conn.execute("CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT)")
    conn.execute("""
        CREATE VIRTUAL TABLE IF NOT EXISTS entry_fts USING fts5(
            title, content, tags,
            entry_id UNINDEXED, project_id UNINDEXED
        )
    """)


def _migration_0001_add_language_column(conn):
    cols = [r[1] for r in conn.execute("PRAGMA table_info(projects)").fetchall()]
    if "language" not in cols:
        conn.execute("ALTER TABLE projects ADD COLUMN language TEXT DEFAULT 'en'")


def _migration_0002_project_paths_created_at(conn):
    pp_cols = [r[1] for r in conn.execute("PRAGMA table_info(project_paths)").fetchall()]
    if "created_at" not in pp_cols:
        conn.execute("ALTER TABLE project_paths ADD COLUMN created_at REAL NOT NULL DEFAULT 0")
        conn.execute("""
            UPDATE project_paths SET created_at = (
                SELECT COALESCE(MIN(pp2.id), 0) FROM project_paths pp2
                WHERE pp2.project_id = project_paths.project_id
            ) * 0.001 + id * 0.001
        """)
        for p in conn.execute("SELECT id, root_path FROM projects").fetchall():
            conn.execute("""
                UPDATE project_paths SET created_at = -1
                WHERE project_id = ? AND path = ?
            """, (p["id"], p["root_path"]))


def _migration_0003_backfill_root_paths(conn):
    for p in conn.execute("SELECT id, root_path FROM projects").fetchall():
        conn.execute(
            "INSERT OR IGNORE INTO project_paths (project_id, path, created_at) VALUES (?, ?, ?)",
            (p["id"], p["root_path"], 0),
        )


def _migration_0004_purge_orphan_embeddings(conn):
    tables = {r[0] for r in conn.execute(
        "SELECT name FROM sqlite_master WHERE type = 'table'"
    ).fetchall()}
    if "entry_embeddings" in tables:
        conn.execute("""
            DELETE FROM entry_embeddings
            WHERE entry_id NOT IN (SELECT id FROM knowledge_entries)
        """)


def _migration_0005_drop_legacy_entry_embeddings(conn):
    conn.execute("DROP TABLE IF EXISTS entry_embeddings")


def _migration_0006_create_and_backfill_fts(conn):
    conn.execute("""
        CREATE VIRTUAL TABLE IF NOT EXISTS entry_fts USING fts5(
            title, content, tags,
            entry_id UNINDEXED, project_id UNINDEXED
        )
    """)
    conn.execute("DELETE FROM entry_fts")
    conn.execute("""
        INSERT INTO entry_fts (title, content, tags, entry_id, project_id)
        SELECT e.title, e.content,
               COALESCE((SELECT GROUP_CONCAT(t.name, ' ') FROM tags t
                         JOIN entry_tags et ON et.tag_id = t.id
                         WHERE et.entry_id = e.id), ''),
               e.id, e.project_id
        FROM knowledge_entries e
        WHERE e.status = 'indexed'
    """)


MIGRATIONS = [
    _migration_0001_add_language_column,
    _migration_0002_project_paths_created_at,
    _migration_0003_backfill_root_paths,
    _migration_0004_purge_orphan_embeddings,
    _migration_0005_drop_legacy_entry_embeddings,
    _migration_0006_create_and_backfill_fts,
]


def init_db():
    conn = get_connection()
    try:
        conn.executescript(BASE_SCHEMA)
        _create_virtual_tables(conn)
        conn.commit()
        version = conn.execute("PRAGMA user_version").fetchone()[0]
        for i, migration in enumerate(MIGRATIONS[version:], start=version + 1):
            migration(conn)
            conn.execute(f"PRAGMA user_version = {i}")
            conn.commit()
    finally:
        conn.close()


def upsert_project(project_id, name, root_path=None, description="", project_type="", language="", paths=None):
    """Create or update a project.

    The "root" path is the one with the smallest created_at in project_paths.
    `root_path` is kept for backwards compatibility; when provided, it is inserted
    with created_at=0 so it becomes the primary path. `paths` (if given) is an
    ordered list of additional paths appended after the root.
    """
    if root_path is None and paths:
        root_path = paths[0]
    if root_path is None:
        raise ValueError("upsert_project requires at least one path")

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
        # Ensure root_path is present as the primary path (created_at=0, smallest)
        conn.execute(
            "INSERT OR IGNORE INTO project_paths (project_id, path, created_at) VALUES (?, ?, ?)",
            (project_id, os.path.abspath(root_path), 0),
        )
        # Append additional paths in order
        if paths:
            for i, p in enumerate(paths):
                if os.path.abspath(p) == os.path.abspath(root_path):
                    continue
                conn.execute(
                    "INSERT OR IGNORE INTO project_paths (project_id, path, created_at) VALUES (?, ?, ?)",
                    (project_id, os.path.abspath(p), now + i + 1),
                )
        conn.commit()
    finally:
        conn.close()


def get_project(project_id):
    conn = get_connection()
    try:
        row = conn.execute("SELECT * FROM projects WHERE id = ?", (project_id,)).fetchone()
        if not row:
            return None
        p = dict(row)
        # Derive root_path from the primary path (first by created_at) for consistency
        paths = list_project_paths(project_id)
        if paths:
            p["root_path"] = paths[0]
        return p
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
    """Associate an additional path with a project (appended after existing ones)."""
    path = os.path.abspath(path)
    conn = get_connection()
    try:
        now = time.time()
        conn.execute(
            "INSERT OR IGNORE INTO project_paths (project_id, path, created_at) VALUES (?, ?, ?)",
            (project_id, path, now),
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
    """List all paths for a project, ordered by creation (first = root/primary)."""
    conn = get_connection()
    try:
        rows = conn.execute(
            "SELECT path FROM project_paths WHERE project_id = ? ORDER BY created_at ASC, id ASC",
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
        entries = [dict(r) for r in rows]
        tags_map = _tags_for_entries(conn, [e["id"] for e in entries])
        for entry in entries:
            entry["tags"] = tags_map[entry["id"]]
            entry["metadata"] = json.loads(entry.get("metadata") or "{}")
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
        _delete_entry_chunks(conn, entry_id)
        conn.execute("DELETE FROM entry_fts WHERE entry_id = ?", (entry_id,))
        conn.execute("DELETE FROM knowledge_entries WHERE id = ?", (entry_id,))
        conn.commit()
    finally:
        conn.close()


def remove_project(project_id):
    """Delete a project, its entries (FK cascade), and their embeddings."""
    conn = get_connection()
    try:
        rows = conn.execute("""
            SELECT rowid FROM chunk_embeddings WHERE entry_id IN
                (SELECT id FROM knowledge_entries WHERE project_id = ?)
        """, (project_id,)).fetchall()
        for r in rows:
            conn.execute("DELETE FROM chunk_embeddings WHERE rowid = ?", (r[0],))
        conn.execute("""
            DELETE FROM entry_fts WHERE entry_id IN
                (SELECT id FROM knowledge_entries WHERE project_id = ?)
        """, (project_id,))
        conn.execute("DELETE FROM projects WHERE id = ?", (project_id,))
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


def _tags_for_entries(conn, entry_ids):
    """Fetch tags for many entries in one query. Returns {entry_id: [tag, ...]}."""
    tags_map = {eid: [] for eid in entry_ids}
    if not entry_ids:
        return tags_map
    placeholders = ",".join("?" for _ in entry_ids)
    rows = conn.execute(f"""
        SELECT et.entry_id, t.name FROM tags t
        JOIN entry_tags et ON et.tag_id = t.id
        WHERE et.entry_id IN ({placeholders})
        ORDER BY t.name
    """, list(entry_ids)).fetchall()
    for r in rows:
        tags_map[r["entry_id"]].append(r["name"])
    return tags_map


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
        entries = [dict(r) for r in rows]
        tags_map = _tags_for_entries(conn, [e["id"] for e in entries])
        for entry in entries:
            entry["tags"] = tags_map[entry["id"]]
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
        entries = [dict(r) for r in rows]
        tags_map = _tags_for_entries(conn, [e["id"] for e in entries])
        for entry in entries:
            entry["tags"] = tags_map[entry["id"]]
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

# KNN over-fetch factor: several chunks can belong to the same entry, so ask
# vec0 for more rows than entries wanted before deduping per entry.
CHUNK_OVERFETCH = 4


def _pack(vector):
    return struct.pack(f"{len(vector)}f", *vector)


def store_entry_embeddings(entry_id, project_id, vectors):
    """Replace all chunk embeddings for an entry (one row per chunk)."""
    conn = get_connection()
    try:
        _delete_entry_chunks(conn, entry_id)
        for i, vec in enumerate(vectors):
            conn.execute(
                "INSERT INTO chunk_embeddings (embedding, project_id, entry_id, chunk_index)"
                " VALUES (?, ?, ?, ?)",
                (_pack(vec), project_id, entry_id, i),
            )
        conn.commit()
    finally:
        conn.close()


def _delete_entry_chunks(conn, entry_id):
    # vec0 DELETE by metadata predicate is version-sensitive; resolve rowids first.
    rows = conn.execute(
        "SELECT rowid FROM chunk_embeddings WHERE entry_id = ?", (entry_id,)
    ).fetchall()
    for r in rows:
        conn.execute("DELETE FROM chunk_embeddings WHERE rowid = ?", (r[0],))


def delete_entry_embeddings(entry_id):
    conn = get_connection()
    try:
        _delete_entry_chunks(conn, entry_id)
        conn.commit()
    finally:
        conn.close()


def search_chunks(query_embedding, project_id=None, k=10):
    """Project-scoped KNN over chunks, deduped per entry (best chunk wins).

    Returns [{"entry_id", "distance"}] ascending by cosine distance, ≤ k items.
    """
    conn = get_connection()
    try:
        sql = "SELECT entry_id, distance FROM chunk_embeddings WHERE embedding MATCH ? AND k = ?"
        params = [_pack(query_embedding), k * CHUNK_OVERFETCH]
        if project_id:
            sql += " AND project_id = ?"
            params.append(project_id)
        sql += " ORDER BY distance"
        rows = conn.execute(sql, params).fetchall()
    finally:
        conn.close()
    best = {}
    for r in rows:
        if r["entry_id"] not in best:
            best[r["entry_id"]] = r["distance"]
    ordered = sorted(best.items(), key=lambda kv: kv[1])[:k]
    return [{"entry_id": eid, "distance": dist} for eid, dist in ordered]


def search_entries_by_embedding(query_embedding, project_id=None, k=10, category=None, tags=None):
    """Search entries by chunk-embedding similarity with category/tag filtering."""
    hits = search_chunks(query_embedding, project_id=project_id, k=k)
    if not hits:
        return []
    entry_ids = [h["entry_id"] for h in hits]
    score_map = {h["entry_id"]: round(1.0 - h["distance"], 4) for h in hits}
    entries = _fetch_entries_in_order(entry_ids, project_id=project_id, category=category, tags=tags)
    for entry in entries:
        entry["score"] = score_map.get(entry["id"], 0.0)
    return entries


RRF_K = 60


def hybrid_search(query, query_embedding, project_id=None, k=10,
                  category=None, tags=None, min_score=0.0):
    """Vector + BM25 search fused with Reciprocal Rank Fusion.

    min_score gates the vector list only: FTS hits are exact keyword matches
    and always pass. Results carry `score` (cosine sim of best chunk, 0.0 for
    FTS-only hits) and `rrf`, ordered by rrf desc.
    """
    vec_hits = search_chunks(query_embedding, project_id=project_id, k=k)
    vec_hits = [h for h in vec_hits if 1.0 - h["distance"] >= min_score]
    fts_hits = search_fts(query, project_id=project_id, k=k)

    rrf = {}
    sim = {}
    for rank, h in enumerate(vec_hits, start=1):
        rrf[h["entry_id"]] = rrf.get(h["entry_id"], 0.0) + 1.0 / (RRF_K + rank)
        sim[h["entry_id"]] = round(1.0 - h["distance"], 4)
    for rank, h in enumerate(fts_hits, start=1):
        rrf[h["entry_id"]] = rrf.get(h["entry_id"], 0.0) + 1.0 / (RRF_K + rank)

    if not rrf:
        return []
    ordered_ids = sorted(rrf, key=rrf.get, reverse=True)
    entries = _fetch_entries_in_order(ordered_ids, project_id=project_id,
                                      category=category, tags=tags)
    for e in entries:
        e["score"] = sim.get(e["id"], 0.0)
        e["rrf"] = round(rrf[e["id"]], 6)
    return entries[:k]


def _fetch_entries_in_order(entry_ids, project_id=None, category=None, tags=None):
    """Hydrate entries by id, preserving the given order, with optional filters."""
    placeholders = ",".join("?" for _ in entry_ids)
    conditions = [f"ke.id IN ({placeholders})"]
    params = list(entry_ids)
    if project_id:
        conditions.append("ke.project_id = ?")
        params.append(project_id)
    if category:
        conditions.append("ke.category = ?")
        params.append(category)
    sql = f"SELECT ke.* FROM knowledge_entries ke WHERE {' AND '.join(conditions)}"
    if tags:
        for tag in tags:
            sql += (" AND ke.id IN (SELECT et.entry_id FROM entry_tags et"
                    " JOIN tags t ON t.id = et.tag_id WHERE t.name = ?"
                    " AND t.project_id = ke.project_id)")
            params.append(tag.lower())
    conn = get_connection()
    try:
        rows = conn.execute(sql, params).fetchall()
        entries = [dict(r) for r in rows]
        tags_map = _tags_for_entries(conn, [e["id"] for e in entries])
    finally:
        conn.close()
    order = {eid: i for i, eid in enumerate(entry_ids)}
    for e in entries:
        e["tags"] = tags_map[e["id"]]
        e["metadata"] = json.loads(e.get("metadata") or "{}")
    entries.sort(key=lambda e: order[e["id"]])
    return entries


# ---- Embedding metadata (model versioning) ----

def get_embedding_meta():
    conn = get_connection()
    try:
        rows = conn.execute(
            "SELECT key, value FROM meta WHERE key IN ('embedding_model', 'embedding_dim')"
        ).fetchall()
    finally:
        conn.close()
    kv = {r["key"]: r["value"] for r in rows}
    dim = kv.get("embedding_dim")
    return {"model": kv.get("embedding_model"), "dim": int(dim) if dim else None}


def set_embedding_meta(model, dim):
    conn = get_connection()
    try:
        conn.execute("INSERT OR REPLACE INTO meta (key, value) VALUES ('embedding_model', ?)", (model,))
        conn.execute("INSERT OR REPLACE INTO meta (key, value) VALUES ('embedding_dim', ?)", (str(dim),))
        conn.commit()
    finally:
        conn.close()


def rebuild_chunk_table():
    """Drop and recreate chunk_embeddings (used when the embedding model changes)."""
    conn = get_connection()
    try:
        conn.execute("DROP TABLE IF EXISTS chunk_embeddings")
        _create_virtual_tables(conn)
        conn.commit()
    finally:
        conn.close()


# ---- Full-text (FTS5 / BM25) operations ----


def _fts_escape(query):
    """Quote each token so user text can't inject FTS5 syntax."""
    tokens = [t for t in query.split() if t]
    return " ".join('"' + t.replace('"', '""') + '"' for t in tokens)


def fts_index_entry(entry):
    """Insert or replace an entry's row in the FTS index."""
    conn = get_connection()
    try:
        conn.execute("DELETE FROM entry_fts WHERE entry_id = ?", (entry["id"],))
        conn.execute(
            "INSERT INTO entry_fts (title, content, tags, entry_id, project_id)"
            " VALUES (?, ?, ?, ?, ?)",
            (entry["title"], entry["content"], " ".join(entry.get("tags") or []),
             entry["id"], entry["project_id"]),
        )
        conn.commit()
    finally:
        conn.close()


def fts_delete_entry(entry_id):
    conn = get_connection()
    try:
        conn.execute("DELETE FROM entry_fts WHERE entry_id = ?", (entry_id,))
        conn.commit()
    finally:
        conn.close()


def search_fts(query, project_id=None, k=10):
    """BM25 search. Returns [{"entry_id", "rank"}], most relevant first."""
    match = _fts_escape(query)
    if not match:
        return []
    sql = "SELECT entry_id, rank FROM entry_fts WHERE entry_fts MATCH ?"
    params = [match]
    if project_id:
        sql += " AND project_id = ?"
        params.append(project_id)
    sql += " ORDER BY rank LIMIT ?"
    params.append(k)
    conn = get_connection()
    try:
        rows = conn.execute(sql, params).fetchall()
        return [dict(r) for r in rows]
    finally:
        conn.close()


# ---- Knowledge graph operations ----


def _normalize_entity_name(name):
    """Strip, lowercase, and collapse internal whitespace for entity dedup."""
    return re.sub(r"\s+", " ", name.strip().lower())


def upsert_entity(project_id, name, type=""):
    """Insert or get an entity by (project_id, normalized name). Returns entity id.

    Keeps the first-seen display name; updates `type` only if the existing
    row currently has no type and a non-empty one is given.
    """
    norm = _normalize_entity_name(name)
    conn = get_connection()
    try:
        now = time.time()
        conn.execute("""
            INSERT OR IGNORE INTO entities (project_id, name, norm_name, type, created_at)
            VALUES (?, ?, ?, ?, ?)
        """, (project_id, name.strip(), norm, type, now))
        row = conn.execute(
            "SELECT id, type FROM entities WHERE project_id = ? AND norm_name = ?",
            (project_id, norm),
        ).fetchone()
        if type and not row["type"]:
            conn.execute("UPDATE entities SET type = ? WHERE id = ?", (type, row["id"]))
        conn.commit()
        return row["id"]
    finally:
        conn.close()


def link_entry_entity(entry_id, entity_id):
    """Link an entry to an entity. Idempotent."""
    conn = get_connection()
    try:
        conn.execute(
            "INSERT OR IGNORE INTO entry_entities (entry_id, entity_id) VALUES (?, ?)",
            (entry_id, entity_id),
        )
        conn.commit()
    finally:
        conn.close()


def add_relation(project_id, subject, predicate, obj, entry_id=None):
    """Add a subject-predicate-object relation. `subject`/`obj` are entity names.

    Upserts both entities, inserts the relation (idempotent via the unique
    index), and when `entry_id` is given also links both entities to the
    entry via entry_entities. Returns the relation id.
    """
    subject_id = upsert_entity(project_id, subject)
    object_id = upsert_entity(project_id, obj)
    conn = get_connection()
    try:
        now = time.time()
        conn.execute("""
            INSERT OR IGNORE INTO relations
                (project_id, subject_id, predicate, object_id, entry_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?)
        """, (project_id, subject_id, predicate, object_id, entry_id, now))
        conn.commit()
        row = conn.execute("""
            SELECT id FROM relations
            WHERE project_id = ? AND subject_id = ? AND predicate = ? AND object_id = ?
                AND IFNULL(entry_id, '') = IFNULL(?, '')
        """, (project_id, subject_id, predicate, object_id, entry_id)).fetchone()
        relation_id = row["id"] if row else None
    finally:
        conn.close()

    if entry_id:
        link_entry_entity(entry_id, subject_id)
        link_entry_entity(entry_id, object_id)

    return relation_id


def get_entities_for_entry(entry_id):
    """List entities linked to an entry. Returns [{id, name, type}]."""
    conn = get_connection()
    try:
        rows = conn.execute("""
            SELECT e.id, e.name, e.type FROM entities e
            JOIN entry_entities ee ON ee.entity_id = e.id
            WHERE ee.entry_id = ?
            ORDER BY e.name
        """, (entry_id,)).fetchall()
        return [dict(r) for r in rows]
    finally:
        conn.close()


def get_relations_for_entry(entry_id):
    """List relations recorded for an entry. Returns [{id, subject, predicate, object}]."""
    conn = get_connection()
    try:
        rows = conn.execute("""
            SELECT r.id, s.name as subject, r.predicate, o.name as object
            FROM relations r
            JOIN entities s ON s.id = r.subject_id
            JOIN entities o ON o.id = r.object_id
            WHERE r.entry_id = ?
        """, (entry_id,)).fetchall()
        return [dict(r) for r in rows]
    finally:
        conn.close()


def add_entry_link(from_entry, to_entry, relation="related"):
    """Link two entries directly. Idempotent."""
    conn = get_connection()
    try:
        conn.execute("""
            INSERT OR IGNORE INTO entry_links (from_entry, to_entry, relation)
            VALUES (?, ?, ?)
        """, (from_entry, to_entry, relation))
        conn.commit()
    finally:
        conn.close()


def get_entry_links(entry_id):
    """List entry_links where the entry is either side."""
    conn = get_connection()
    try:
        rows = conn.execute("""
            SELECT from_entry, to_entry, relation FROM entry_links
            WHERE from_entry = ? OR to_entry = ?
        """, (entry_id, entry_id)).fetchall()
        return [dict(r) for r in rows]
    finally:
        conn.close()


def get_graph(project_id):
    """Get the whole project's graph.

    Returns {"entities": [{id, name, type, entry_count}],
             "relations": [{id, subject_id, object_id, predicate, entry_id}]}.
    """
    conn = get_connection()
    try:
        entity_rows = conn.execute("""
            SELECT e.id, e.name, e.type,
                   COUNT(DISTINCT ee.entry_id) as entry_count
            FROM entities e
            LEFT JOIN entry_entities ee ON ee.entity_id = e.id
            WHERE e.project_id = ?
            GROUP BY e.id
            ORDER BY e.name
        """, (project_id,)).fetchall()
        relation_rows = conn.execute("""
            SELECT id, subject_id, object_id, predicate, entry_id
            FROM relations WHERE project_id = ?
        """, (project_id,)).fetchall()
        return {
            "entities": [dict(r) for r in entity_rows],
            "relations": [dict(r) for r in relation_rows],
        }
    finally:
        conn.close()


def _bfs_relations(conn, project_id, start_ids, depth):
    """BFS over relations (undirected) from start_ids up to depth hops.

    Returns (visited_entity_ids, triples, relation_entry_ids) where triples is
    a list of {subject, predicate, object, entry_id} in traversal order and
    relation_entry_ids is the set of non-null entry_id values seen on traversed
    relations.
    """
    frontier = list(start_ids)
    visited = set(frontier)
    triples = []
    seen_relation_ids = set()
    relation_entry_ids = set()
    for _ in range(max(depth, 0)):
        if not frontier:
            break
        placeholders = ",".join("?" for _ in frontier)
        rows = conn.execute(f"""
            SELECT r.id, s.name as subject, r.predicate, o.name as object,
                   r.subject_id, r.object_id, r.entry_id
            FROM relations r
            JOIN entities s ON s.id = r.subject_id
            JOIN entities o ON o.id = r.object_id
            WHERE r.project_id = ? AND (r.subject_id IN ({placeholders}) OR r.object_id IN ({placeholders}))
        """, [project_id] + frontier + frontier).fetchall()
        next_frontier = set()
        for row in rows:
            if row["id"] not in seen_relation_ids:
                seen_relation_ids.add(row["id"])
                triples.append({
                    "subject": row["subject"],
                    "predicate": row["predicate"],
                    "object": row["object"],
                    "entry_id": row["entry_id"],
                })
                if row["entry_id"]:
                    relation_entry_ids.add(row["entry_id"])
            for eid in (row["subject_id"], row["object_id"]):
                if eid not in visited:
                    next_frontier.add(eid)
        visited |= next_frontier
        frontier = list(next_frontier)
    return visited, triples, relation_entry_ids


def query_entity_graph(project_id, entity_name, depth=1):
    """Resolve an entity by normalized name and BFS over relations up to `depth` hops.

    Returns {"entity": {...} | None, "triples": [{subject, predicate, object, entry_id}],
             "entries": [indexed entry dicts linked to any reached entity]}.
    """
    norm = _normalize_entity_name(entity_name)
    conn = get_connection()
    try:
        row = conn.execute(
            "SELECT * FROM entities WHERE project_id = ? AND norm_name = ?",
            (project_id, norm),
        ).fetchone()
        if not row:
            return {"entity": None, "triples": [], "entries": []}
        entity = dict(row)

        visited, triples, _ = _bfs_relations(conn, project_id, [entity["id"]], depth)

        entries = []
        if visited:
            placeholders = ",".join("?" for _ in visited)
            entry_rows = conn.execute(f"""
                SELECT DISTINCT ke.* FROM knowledge_entries ke
                JOIN entry_entities ee ON ee.entry_id = ke.id
                WHERE ee.entity_id IN ({placeholders}) AND ke.status = 'indexed'
                ORDER BY ke.created_at DESC
            """, list(visited)).fetchall()
            entries = [dict(r) for r in entry_rows]
            tags_map = _tags_for_entries(conn, [e["id"] for e in entries])
            for e in entries:
                e["tags"] = tags_map[e["id"]]

        return {"entity": entity, "triples": triples, "entries": entries}
    finally:
        conn.close()


def expand_entries_via_graph(project_id, seed_entry_ids, depth=1, limit=10):
    """Expand vector-search seed entries via the knowledge graph.

    Algorithm: seed entities = union of entry_entities for seeds; BFS `depth`
    hops over relations (undirected); related entries = entries linked to any
    reached entity (via entry_entities or relations.entry_id) plus entry_links
    neighbors of seeds (both directions); filter to status='indexed', exclude
    seeds, dedupe, cap at `limit`.

    Returns {"triples": [{subject, predicate, object, entry_id}],
             "related_entries": [entry dicts with "tags"]}.
    """
    if not seed_entry_ids:
        return {"triples": [], "related_entries": []}

    conn = get_connection()
    try:
        seed_placeholders = ",".join("?" for _ in seed_entry_ids)
        seed_rows = conn.execute(
            f"SELECT DISTINCT entity_id FROM entry_entities WHERE entry_id IN ({seed_placeholders})",
            seed_entry_ids,
        ).fetchall()
        seed_entities = [r["entity_id"] for r in seed_rows]

        visited, triples, relation_entry_ids = _bfs_relations(conn, project_id, seed_entities, depth)

        related_ids = set(relation_entry_ids)
        if visited:
            placeholders = ",".join("?" for _ in visited)
            entity_entry_rows = conn.execute(
                f"SELECT DISTINCT entry_id FROM entry_entities WHERE entity_id IN ({placeholders})",
                list(visited),
            ).fetchall()
            related_ids |= {r["entry_id"] for r in entity_entry_rows}

        link_rows = conn.execute(f"""
            SELECT from_entry, to_entry FROM entry_links
            WHERE from_entry IN ({seed_placeholders}) OR to_entry IN ({seed_placeholders})
        """, list(seed_entry_ids) + list(seed_entry_ids)).fetchall()
        for r in link_rows:
            related_ids.add(r["from_entry"])
            related_ids.add(r["to_entry"])

        related_ids -= set(seed_entry_ids)

        related_entries = []
        if related_ids:
            placeholders = ",".join("?" for _ in related_ids)
            entry_rows = conn.execute(f"""
                SELECT * FROM knowledge_entries
                WHERE id IN ({placeholders}) AND status = 'indexed'
                ORDER BY created_at DESC
                LIMIT ?
            """, list(related_ids) + [limit]).fetchall()
            related_entries = [dict(r) for r in entry_rows]
            tags_map = _tags_for_entries(conn, [e["id"] for e in related_entries])
            for e in related_entries:
                e["tags"] = tags_map[e["id"]]

        return {"triples": triples, "related_entries": related_entries}
    finally:
        conn.close()
