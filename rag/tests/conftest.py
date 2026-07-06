"""Shared test fixtures for RAG knowledge base tests."""
import os
import sys
import tempfile
import pytest

# Make server modules importable
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "server"))


@pytest.fixture(autouse=True)
def clean_env():
    """Force test model and dimension for all tests."""
    old_model = os.environ.get("RAG_EMBEDDING_MODEL")
    old_dim = os.environ.get("RAG_EMBEDDING_DIM")
    os.environ["RAG_EMBEDDING_MODEL"] = "all-MiniLM-L6-v2"
    os.environ["RAG_EMBEDDING_DIM"] = "384"
    yield
    if old_model:
        os.environ["RAG_EMBEDDING_MODEL"] = old_model
    else:
        os.environ.pop("RAG_EMBEDDING_MODEL", None)
    if old_dim:
        os.environ["RAG_EMBEDDING_DIM"] = old_dim
    else:
        os.environ.pop("RAG_EMBEDDING_DIM", None)


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


@pytest.fixture
def client(temp_db):
    """FastAPI TestClient with a temp database."""
    from fastapi.testclient import TestClient
    import api
    api.db = temp_db
    with TestClient(api.app) as c:
        yield c
