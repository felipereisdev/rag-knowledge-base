"""Tests for the FastAPI REST API."""
import pytest


class TestProjects:
    def test_list_projects_empty(self, client):
        resp = client.get("/api/projects")
        assert resp.status_code == 200
        assert resp.json() == []

    def test_create_project(self, client):
        resp = client.post("/api/projects", json={
            "id": "test-proj",
            "name": "Test Project",
            "root_path": "/tmp/test",
            "description": "A test project",
            "language": "en",
        })
        assert resp.status_code == 201
        data = resp.json()
        assert data["id"] == "test-proj"
        assert data["name"] == "Test Project"
        assert data["language"] == "en"

    def test_get_project(self, client):
        client.post("/api/projects", json={
            "id": "test-proj", "name": "Test", "root_path": "/tmp/test",
        })
        resp = client.get("/api/projects/test-proj")
        assert resp.status_code == 200
        assert resp.json()["name"] == "Test"

    def test_get_project_not_found(self, client):
        resp = client.get("/api/projects/nonexistent")
        assert resp.status_code == 404

    def test_update_project(self, client):
        client.post("/api/projects", json={
            "id": "test-proj", "name": "Test", "root_path": "/tmp/test",
        })
        resp = client.put("/api/projects/test-proj", json={
            "name": "Updated", "language": "pt-BR",
        })
        assert resp.status_code == 200
        assert resp.json()["name"] == "Updated"
        assert resp.json()["language"] == "pt-BR"

    def test_delete_project(self, client):
        client.post("/api/projects", json={
            "id": "test-proj", "name": "Test", "root_path": "/tmp/test",
        })
        resp = client.delete("/api/projects/test-proj")
        assert resp.status_code == 204
        assert client.get("/api/projects/test-proj").status_code == 404

    def test_list_projects_with_stats(self, client):
        client.post("/api/projects", json={
            "id": "p1", "name": "P1", "root_path": "/tmp/p1",
        })
        resp = client.get("/api/projects")
        assert resp.status_code == 200
        data = resp.json()
        assert len(data) == 1
        assert data[0]["indexed_count"] == 0
        assert data[0]["pending_count"] == 0
