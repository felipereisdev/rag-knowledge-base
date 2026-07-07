"""Tests for sentence-transformers embedding storage and vector search."""
import pytest


class TestEmbedText:
    def test_embed_text_returns_vector(self, temp_db):
        import embeddings
        vec = embeddings.embed_text("test text")
        assert len(vec) == 384
        assert all(isinstance(v, float) for v in vec)

    def test_embed_empty_text(self, temp_db):
        import embeddings
        vec = embeddings.embed_text("")
        assert len(vec) == 384


class TestChunkEmbeddingCRUD:
    def _entry(self, temp_db, project="p1", title="T", content="c"):
        temp_db.upsert_project(project, project, f"/tmp/{project}")
        return temp_db.store_knowledge_entry(project, title, content)

    def test_store_and_search_chunks(self, temp_db):
        import embeddings
        eid = self._entry(temp_db, content="Orders over 1000 need manager approval")
        temp_db.store_entry_embeddings(
            eid, "p1", [embeddings.embed_text("Orders over 1000 need manager approval")]
        )
        hits = temp_db.search_chunks(embeddings.embed_query("order approval"), project_id="p1", k=5)
        assert hits and hits[0]["entry_id"] == eid

    def test_store_replaces_previous_chunks(self, temp_db):
        import embeddings
        eid = self._entry(temp_db)
        temp_db.store_entry_embeddings(eid, "p1", [embeddings.embed_text("first"), embeddings.embed_text("second")])
        temp_db.store_entry_embeddings(eid, "p1", [embeddings.embed_text("third")])
        conn = temp_db.get_connection()
        try:
            count = conn.execute(
                "SELECT COUNT(*) FROM chunk_embeddings WHERE entry_id = ?", (eid,)
            ).fetchone()[0]
        finally:
            conn.close()
        assert count == 1

    def test_delete_entry_embeddings(self, temp_db):
        import embeddings
        eid = self._entry(temp_db)
        temp_db.store_entry_embeddings(eid, "p1", [embeddings.embed_text("x")])
        temp_db.delete_entry_embeddings(eid)
        hits = temp_db.search_chunks(embeddings.embed_query("x"), project_id="p1", k=5)
        assert hits == []

    def test_multichunk_entry_dedupes_to_best_chunk(self, temp_db):
        import embeddings
        eid = self._entry(temp_db)
        vecs = [embeddings.embed_text("payments through stripe"), embeddings.embed_text("refunds in 30 days")]
        temp_db.store_entry_embeddings(eid, "p1", vecs)
        hits = temp_db.search_chunks(embeddings.embed_query("stripe payments"), project_id="p1", k=5)
        assert len(hits) == 1
        assert hits[0]["entry_id"] == eid


class TestProjectScopedSearch:
    def test_knn_is_scoped_to_project_before_ranking(self, temp_db):
        """The old bug: global top-k then project filter starved small projects."""
        import embeddings
        temp_db.upsert_project("big", "Big", "/tmp/big")
        temp_db.upsert_project("small", "Small", "/tmp/small")
        # 8 close-to-query entries in "big" would previously eat all k=5 slots
        for i in range(8):
            eid = temp_db.store_knowledge_entry("big", f"Order rule {i}", f"order approval workflow variant {i}")
            temp_db.store_entry_embeddings(eid, "big", [embeddings.embed_text(f"order approval workflow variant {i}")])
        target = temp_db.store_knowledge_entry("small", "Order rule", "order approval workflow")
        temp_db.store_entry_embeddings(target, "small", [embeddings.embed_text("order approval workflow")])

        hits = temp_db.search_chunks(embeddings.embed_query("order approval"), project_id="small", k=5)
        assert [h["entry_id"] for h in hits] == [target]

    def test_search_entries_score_is_cosine_similarity(self, temp_db):
        import embeddings
        temp_db.upsert_project("p1", "P1", "/tmp/p1")
        eid = temp_db.store_knowledge_entry("p1", "Order rule", "Orders over 1000 need approval")
        temp_db.store_entry_embeddings(eid, "p1", [embeddings.embed_text("Orders over 1000 need approval")])
        results = temp_db.search_entries_by_embedding(
            embeddings.embed_query("order approval"), project_id="p1", k=5
        )
        assert results and results[0]["id"] == eid
        assert 0.0 < results[0]["score"] <= 1.0


class TestEmbeddingMeta:
    def test_meta_roundtrip(self, temp_db):
        assert temp_db.get_embedding_meta() == {"model": None, "dim": None}
        temp_db.set_embedding_meta("all-MiniLM-L6-v2", 384)
        assert temp_db.get_embedding_meta() == {"model": "all-MiniLM-L6-v2", "dim": 384}

    def test_rebuild_chunk_table_empties_it(self, temp_db):
        import embeddings
        temp_db.upsert_project("p1", "P1", "/tmp/p1")
        eid = temp_db.store_knowledge_entry("p1", "T", "c")
        temp_db.store_entry_embeddings(eid, "p1", [embeddings.embed_text("c")])
        temp_db.rebuild_chunk_table()
        hits = temp_db.search_chunks(embeddings.embed_query("c"), project_id="p1", k=5)
        assert hits == []


class TestRemoteEmbedding:
    def test_embed_text_uses_remote_when_available(self, temp_db, monkeypatch):
        import embeddings
        monkeypatch.setattr(embeddings, "serving_locally", False)
        monkeypatch.setenv("RAG_EMBED_REMOTE", "1")
        fake_vec = [0.5] * embeddings.EMBEDDING_DIM
        monkeypatch.setattr(embeddings, "_embed_remote", lambda texts: [fake_vec for _ in texts])
        assert embeddings.embed_text("hello") == fake_vec

    def test_embed_text_falls_back_to_local_on_remote_failure(self, temp_db, monkeypatch):
        import embeddings
        monkeypatch.setattr(embeddings, "serving_locally", False)
        monkeypatch.setenv("RAG_EMBED_REMOTE", "1")
        monkeypatch.setattr(embeddings, "_embed_remote", lambda texts: None)
        vec = embeddings.embed_text("hello")
        assert len(vec) == embeddings.EMBEDDING_DIM

    def test_serving_locally_skips_remote(self, temp_db, monkeypatch):
        import embeddings
        monkeypatch.setattr(embeddings, "serving_locally", True)
        calls = []
        monkeypatch.setattr(embeddings, "_embed_remote", lambda texts: calls.append(1) or None)
        embeddings.embed_text("hello")
        assert calls == []
