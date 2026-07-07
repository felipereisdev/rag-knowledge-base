"""Entry indexing pipeline: chunk content, embed each chunk, store vectors.

Also owns the embedding model-version guard: when the configured model or
dimension differs from what the index was built with, the whole index is
rebuilt (a dimension change even invalidates the vec0 table's DDL).
"""
import chunking
import db
import embeddings


def index_entry(entry):
    """Make one entry searchable. `entry` needs id, project_id, title, content, tags."""
    chunks = chunking.chunk_text(entry["content"])
    if chunks:
        texts = [f"{entry['title']}\n\n{chunk}" for chunk in chunks]
    else:
        texts = [entry["title"]]
    vectors = [embeddings.embed_text(t) for t in texts]
    db.store_entry_embeddings(entry["id"], entry["project_id"], vectors)
    db.fts_index_entry(entry)


def unindex_entry(entry_id):
    db.delete_entry_embeddings(entry_id)
    db.fts_delete_entry(entry_id)


def ensure_index_current(log=None):
    """Rebuild the vector index if the embedding model or dimension changed.

    Also covers the first run against a pre-existing database (meta empty but
    indexed entries present, e.g. right after the legacy-table migration):
    every indexed entry is (re-)embedded so nothing is left unsearchable.
    """
    meta = db.get_embedding_meta()
    current = {"model": embeddings.MODEL_NAME, "dim": embeddings.EMBEDDING_DIM}
    if meta == current:
        return
    if log and meta["model"] is not None:
        log(f"Embedding config changed ({meta['model']}/{meta['dim']} -> "
            f"{current['model']}/{current['dim']}); rebuilding vector index...")
    db.rebuild_chunk_table()
    total = 0
    for project in db.list_projects():
        for entry in db.get_indexed_entries(project["id"]):
            index_entry(entry)
            total += 1
    if log and total > 0:
        if meta["model"] is not None:
            log(f"Re-embedded {total} entries.")
        else:
            log(f"Building vector index for {total} entries.")
    db.set_embedding_meta(current["model"], current["dim"])
