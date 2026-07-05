"""Tests for the knowledge base search engine."""
import pytest
from search_engine import TFIDFIndex, build_index_from_entries, tokenize


class TestTokenize:
    def test_basic(self):
        tokens = tokenize("Orders over 1000 need approval")
        assert "orders" in tokens
        assert "1000" in tokens
        assert "approval" in tokens

    def test_lowercase(self):
        tokens = tokenize("JWT Authentication")
        assert "jwt" in tokens
        assert "authentication" in tokens

    def test_empty(self):
        assert tokenize("") == []


class TestSearch:
    def test_search_returns_relevant(self):
        entries = [
            {"id": "1", "title": "Order approval rule", "content": "Orders over 1000 need manager approval", "category": "business-rule", "tags": []},
            {"id": "2", "title": "Auth architecture", "content": "We use JWT with refresh tokens stored in Redis", "category": "architecture", "tags": []},
            {"id": "3", "title": "Database choice", "content": "Postgres for relational data, Redis for cache", "category": "design-decision", "tags": []},
        ]
        index = build_index_from_entries(entries)
        results = index.search("order approval")
        assert len(results) > 0
        assert results[0]["id"] == "1"

    def test_search_returns_empty_when_no_match(self):
        entries = [
            {"id": "1", "title": "Auth", "content": "JWT tokens", "category": "architecture", "tags": []},
        ]
        index = build_index_from_entries(entries)
        results = index.search("database migration")
        assert results == []

    def test_search_includes_title_and_content(self):
        entries = [
            {"id": "1", "title": "Payment Gateway", "content": "Stripe integration for card payments", "category": "architecture", "tags": []},
            {"id": "2", "title": "Other", "content": "unrelated content", "category": "insight", "tags": []},
        ]
        index = build_index_from_entries(entries)
        results = index.search("payment")
        assert len(results) == 1
        assert results[0]["id"] == "1"

    def test_search_includes_tags(self):
        entries = [
            {"id": "1", "title": "Rule", "content": "some content here", "category": "insight", "tags": ["auth", "security"]},
            {"id": "2", "title": "Other", "content": "different content", "category": "insight", "tags": ["database"]},
        ]
        index = build_index_from_entries(entries)
        results = index.search("auth security")
        assert len(results) == 1
        assert results[0]["id"] == "1"

    def test_search_filter_by_category(self):
        entries = [
            {"id": "1", "title": "Order rule", "content": "approval needed", "category": "business-rule", "tags": []},
            {"id": "2", "title": "Order system", "content": "approval workflow", "category": "architecture", "tags": []},
        ]
        index = build_index_from_entries(entries)
        results = index.search("approval", category="business-rule")
        assert len(results) == 1
        assert results[0]["id"] == "1"

    def test_search_filter_by_tags(self):
        entries = [
            {"id": "1", "title": "Rule", "content": "content about auth", "category": "insight", "tags": ["auth"]},
            {"id": "2", "title": "Rule2", "content": "content about auth", "category": "insight", "tags": ["db"]},
        ]
        index = build_index_from_entries(entries)
        results = index.search("auth", tags=["auth"])
        assert len(results) == 1
        assert results[0]["id"] == "1"

    def test_search_score_ordering(self):
        entries = [
            {"id": "1", "title": "Redis cache", "content": "redis redis redis cache cache", "category": "insight", "tags": []},
            {"id": "2", "title": "Other", "content": "redis mentioned once", "category": "insight", "tags": []},
        ]
        index = build_index_from_entries(entries)
        results = index.search("redis cache")
        assert len(results) == 2
        assert results[0]["id"] == "1"
        assert results[0]["score"] >= results[1]["score"]

    def test_search_top_k(self):
        entries = [
            {"id": str(i), "title": f"Entry {i}", "content": "common word here", "category": "insight", "tags": []}
            for i in range(10)
        ]
        index = build_index_from_entries(entries)
        results = index.search("common", top_k=3)
        assert len(results) == 3
