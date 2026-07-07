"""Integration tests for the full knowledge base workflow."""
import os
import tempfile
import pytest


class TestFullWorkflow:
    def test_store_approve_search(self, temp_db):
        import embeddings
        temp_db.upsert_project("shop", "Shop App", "/tmp/shop", "E-commerce app", "")

        eid1 = temp_db.store_knowledge_entry(
            "shop", "Order approval rule",
            "Orders over 1000 require manager approval",
            "business-rule", tags=["orders", "approval"]
        )
        eid2 = temp_db.store_knowledge_entry(
            "shop", "Auth architecture",
            "JWT with refresh tokens stored in Redis",
            "architecture", tags=["auth", "security"]
        )

        temp_db.approve_entries([eid1, eid2])

        import indexing
        indexing.index_entry(temp_db.get_entry(eid1))
        indexing.index_entry(temp_db.get_entry(eid2))

        query_vec = embeddings.embed_query("order approval")
        results = temp_db.search_entries_by_embedding(query_vec, project_id="shop", k=5)
        assert len(results) >= 1
        assert results[0]["title"] == "Order approval rule"

        query_vec = embeddings.embed_query("auth")
        results = temp_db.search_entries_by_embedding(query_vec, project_id="shop", k=5)
        assert len(results) >= 1
        assert "Auth architecture" in [r["title"] for r in results]

    def test_import_markdown_and_search(self, temp_db):
        import embeddings
        temp_db.upsert_project("docs", "Docs", "/tmp/docs", "", "")

        md_content = """---
category: business-rule
tags: payments, stripe
---

# Payment Rule

All payments go through Stripe. Refunds within 30 days.

# Refund Policy

Full refund within 30 days. Partial after that.
"""
        with tempfile.NamedTemporaryFile(mode="w", suffix=".md", delete=False) as f:
            f.write(md_content)
            filepath = f.name

        try:
            import doc_import
            entry_ids = doc_import.import_document(temp_db, "docs", filepath)
            assert len(entry_ids) == 2

            temp_db.approve_entries(entry_ids)

            import indexing
            for eid in entry_ids:
                indexing.index_entry(temp_db.get_entry(eid))

            query_vec = embeddings.embed_query("stripe payment")
            results = temp_db.search_entries_by_embedding(query_vec, project_id="docs", k=5)
            assert len(results) >= 1
            assert "Payment" in results[0]["title"]

            query_vec = embeddings.embed_query("refund")
            results = temp_db.search_entries_by_embedding(query_vec, project_id="docs", k=5)
            assert len(results) >= 1
        finally:
            os.unlink(filepath)
