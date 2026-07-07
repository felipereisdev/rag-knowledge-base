"""Tests for the entry indexing pipeline."""


def _make_entry(temp_db, content, project="p1", title="T1"):
    temp_db.upsert_project(project, project, f"/tmp/{project}")
    eid = temp_db.store_knowledge_entry(project, title, content)
    temp_db.approve_entries([eid])
    return temp_db.get_entry(eid)


class TestIndexEntry:
    def test_long_entry_gets_multiple_chunks(self, temp_db):
        import indexing
        long_content = "\n\n".join(f"Paragraph {i}: " + ("detail " * 60) for i in range(4))
        entry = _make_entry(temp_db, long_content)
        indexing.index_entry(entry)
        conn = temp_db.get_connection()
        try:
            count = conn.execute(
                "SELECT COUNT(*) FROM chunk_embeddings WHERE entry_id = ?", (entry["id"],)
            ).fetchone()[0]
        finally:
            conn.close()
        assert count >= 2

    def test_tail_of_long_entry_is_searchable(self, temp_db):
        """The 128-token truncation bug: content past the model window must match."""
        import indexing, embeddings
        filler = "\n\n".join("Generic paragraph about the weather. " * 20 for _ in range(3))
        content = filler + "\n\nRefunds are processed through Stripe within 30 days."
        entry = _make_entry(temp_db, content)
        indexing.index_entry(entry)
        hits = temp_db.search_chunks(
            embeddings.embed_query("stripe refunds"), project_id="p1", k=5
        )
        assert [h["entry_id"] for h in hits] == [entry["id"]]

    def test_empty_content_still_indexes_title(self, temp_db):
        import indexing, embeddings
        entry = _make_entry(temp_db, "", title="Payment architecture")
        indexing.index_entry(entry)
        hits = temp_db.search_chunks(
            embeddings.embed_query("payment architecture"), project_id="p1", k=5
        )
        assert hits and hits[0]["entry_id"] == entry["id"]

    def test_unindex_entry(self, temp_db):
        import indexing, embeddings
        entry = _make_entry(temp_db, "some content")
        indexing.index_entry(entry)
        indexing.unindex_entry(entry["id"])
        hits = temp_db.search_chunks(embeddings.embed_query("some content"), project_id="p1", k=5)
        assert hits == []


class TestEnsureIndexCurrent:
    def test_first_run_stamps_meta(self, temp_db):
        import indexing, embeddings
        indexing.ensure_index_current()
        meta = temp_db.get_embedding_meta()
        assert meta == {"model": embeddings.MODEL_NAME, "dim": embeddings.EMBEDDING_DIM}

    def test_model_change_triggers_reindex(self, temp_db):
        import indexing, embeddings
        entry = _make_entry(temp_db, "Orders over 1000 need approval")
        indexing.index_entry(entry)
        # Pretend the previous index was built by another model
        temp_db.set_embedding_meta("old-model", embeddings.EMBEDDING_DIM)
        indexing.ensure_index_current()
        meta = temp_db.get_embedding_meta()
        assert meta["model"] == embeddings.MODEL_NAME
        hits = temp_db.search_chunks(
            embeddings.embed_query("order approval"), project_id="p1", k=5
        )
        assert hits and hits[0]["entry_id"] == entry["id"]
