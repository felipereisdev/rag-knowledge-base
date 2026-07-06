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


class TestEmbeddingCRUD:
    def test_store_and_get_embedding(self, temp_db):
        import embeddings
        vec = embeddings.embed_text("hello world")
        temp_db.store_embedding("e1", vec)
        stored = temp_db.get_embedding("e1")
        assert stored is not None
        assert stored["entry_id"] == "e1"
        assert len(stored["embedding"]) == 384

    def test_delete_embedding(self, temp_db):
        import embeddings
        vec = embeddings.embed_text("test")
        temp_db.store_embedding("e1", vec)
        temp_db.delete_embedding("e1")
        assert temp_db.get_embedding("e1") is None

    def test_replace_embedding(self, temp_db):
        import embeddings
        temp_db.store_embedding("e1", embeddings.embed_text("first"))
        temp_db.store_embedding("e1", embeddings.embed_text("second"))
        stored = temp_db.get_embedding("e1")
        assert stored is not None


class TestVectorSearch:
    def _setup(self, temp_db):
        import embeddings
        e1 = "order-approval"
        e2 = "auth-jwt"
        temp_db.store_embedding(e1, embeddings.embed_text("Orders over 1000 need manager approval"))
        temp_db.store_embedding(e2, embeddings.embed_text("JWT authentication with refresh tokens"))
        return e1, e2

    def test_search_returns_similar(self, temp_db):
        import embeddings
        e1, _ = self._setup(temp_db)
        query_vec = embeddings.embed_query("order approval workflow")
        results = temp_db.search_embeddings(query_vec, k=5)
        assert len(results) >= 1
        assert results[0]["entry_id"] == e1

    def test_search_top_k(self, temp_db):
        import embeddings
        self._setup(temp_db)
        query_vec = embeddings.embed_query("test")
        results = temp_db.search_embeddings(query_vec, k=1)
        assert len(results) <= 1

    def test_search_no_results(self, temp_db):
        import embeddings
        query_vec = embeddings.embed_query("anything")
        results = temp_db.search_embeddings(query_vec, k=5)
        assert results == []

    def test_search_entries_by_embedding_with_filters(self, temp_db):
        import embeddings
        """Create a project and entries via db then search"""
        temp_db.upsert_project("proj1", "Proj1", "/tmp/p1")
        temp_db.upsert_project("proj2", "Proj2", "/tmp/p2")
        e1 = temp_db.store_knowledge_entry("proj1", "Order rule", "Orders over 1000 need approval",
                                            category="business-rule", tags=["orders"])
        e2 = temp_db.store_knowledge_entry("proj2", "Auth architecture", "JWT tokens in Redis",
                                            category="architecture", tags=["auth"])
        # Store embeddings for both
        vec1 = embeddings.embed_text("Orders over 1000 need approval")
        vec2 = embeddings.embed_text("JWT tokens in Redis")
        temp_db.store_embedding(e1, vec1)
        temp_db.store_embedding(e2, vec2)
        query_vec = embeddings.embed_query("approval")
        results = temp_db.search_entries_by_embedding(query_vec, project_id="proj1", k=5)
        assert len(results) == 1
        assert results[0]["id"] == e1
