"""Shared test fixtures for RAG knowledge base tests."""
import os
import sys
import tempfile
import pytest

# Make server modules importable
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "server"))


@pytest.fixture
def temp_db(monkeypatch):
    """Use a temporary database file for each test."""
    with tempfile.TemporaryDirectory() as tmpdir:
        db_path = os.path.join(tmpdir, "test_knowledge.db")
        monkeypatch.setattr("db.DB_PATH", db_path)
        monkeypatch.setattr("db.DATA_DIR", tmpdir)
        import db
        db.init_db()
        yield db
