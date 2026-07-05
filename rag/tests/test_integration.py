"""Integration tests for the full knowledge base workflow."""
import os
import tempfile
import pytest


class TestFullWorkflow:
    def test_store_approve_search(self, temp_db):
        # Setup project
        temp_db.upsert_project("shop", "Shop App", "/tmp/shop", "E-commerce app", "")

        # Store knowledge
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

        # Approve both
        temp_db.approve_entries([eid1, eid2])

        # Search
        import search_engine
        entries = temp_db.get_indexed_entries("shop")
        index = search_engine.build_index_from_entries(entries)

        results = index.search("order approval")
        assert len(results) >= 1
        assert results[0]["title"] == "Order approval rule"

        results = index.search("auth")
        assert len(results) >= 1
        assert results[0]["title"] == "Auth architecture"

    def test_import_markdown_and_search(self, temp_db):
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

            # Approve all
            temp_db.approve_entries(entry_ids)

            # Search
            import search_engine
            entries = temp_db.get_indexed_entries("docs")
            index = search_engine.build_index_from_entries(entries)

            results = index.search("stripe payment")
            assert len(results) >= 1
            assert "Payment" in results[0]["title"]

            results = index.search("refund")
            assert len(results) >= 1
        finally:
            os.unlink(filepath)

    def test_filter_by_category_and_tags(self, temp_db):
        temp_db.upsert_project("proj", "Project", "/tmp/proj", "", "")

        temp_db.store_knowledge_entry("proj", "R1", "order content", "business-rule", tags=["orders"])
        temp_db.store_knowledge_entry("proj", "R2", "auth content", "architecture", tags=["auth"])
        temp_db.store_knowledge_entry("proj", "R3", "db content", "architecture", tags=["db", "auth"])

        entries = temp_db.list_entries("proj", category="architecture")
        assert len(entries) == 2

        entries = temp_db.list_entries("proj", tags=["auth"])
        assert len(entries) == 2

    def test_reject_then_not_in_search(self, temp_db):
        temp_db.upsert_project("proj", "Project", "/tmp/proj", "", "")

        eid = temp_db.store_knowledge_entry("proj", "Rule", "some content", "insight")
        temp_db.reject_entries([eid])

        entries = temp_db.get_indexed_entries("proj")
        assert len(entries) == 0
