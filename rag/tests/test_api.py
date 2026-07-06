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


class TestEntries:
    def _setup_project(self, client, pid="test-proj"):
        client.post("/api/projects", json={
            "id": pid, "name": "Test", "root_path": "/tmp/test",
        })

    def test_list_entries_empty(self, client):
        self._setup_project(client)
        resp = client.get("/api/entries?project_id=test-proj")
        assert resp.status_code == 200
        assert resp.json() == []

    def test_create_entry(self, client):
        self._setup_project(client)
        resp = client.post("/api/entries", json={
            "project_id": "test-proj",
            "title": "Order rule",
            "content": "Orders over 1000 need approval",
            "category": "business-rule",
            "tags": ["orders", "approval"],
        })
        assert resp.status_code == 201
        data = resp.json()
        assert data["title"] == "Order rule"
        assert data["status"] == "pending"
        assert set(data["tags"]) == {"orders", "approval"}

    def test_get_entry(self, client):
        self._setup_project(client)
        create = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Rule", "content": "content",
        })
        eid = create.json()["id"]
        resp = client.get(f"/api/entries/{eid}")
        assert resp.status_code == 200
        assert resp.json()["title"] == "Rule"

    def test_get_entry_not_found(self, client):
        resp = client.get("/api/entries/nonexistent-id")
        assert resp.status_code == 404

    def test_update_entry(self, client):
        self._setup_project(client)
        create = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Rule", "content": "content",
        })
        eid = create.json()["id"]
        resp = client.put(f"/api/entries/{eid}", json={
            "title": "Updated Rule",
            "content": "new content",
            "category": "architecture",
            "tags": ["auth"],
        })
        assert resp.status_code == 200
        assert resp.json()["title"] == "Updated Rule"
        assert resp.json()["category"] == "architecture"
        assert resp.json()["tags"] == ["auth"]

    def test_delete_entry(self, client):
        self._setup_project(client)
        create = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Rule", "content": "content",
        })
        eid = create.json()["id"]
        resp = client.delete(f"/api/entries/{eid}")
        assert resp.status_code == 204
        assert client.get(f"/api/entries/{eid}").status_code == 404

    def test_approve_entry(self, client):
        self._setup_project(client)
        create = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Rule", "content": "content",
        })
        eid = create.json()["id"]
        resp = client.post(f"/api/entries/{eid}/approve")
        assert resp.status_code == 200
        entry = client.get(f"/api/entries/{eid}").json()
        assert entry["status"] == "indexed"

    def test_reject_entry(self, client):
        self._setup_project(client)
        create = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Rule", "content": "content",
        })
        eid = create.json()["id"]
        resp = client.post(f"/api/entries/{eid}/reject")
        assert resp.status_code == 200
        entry = client.get(f"/api/entries/{eid}").json()
        assert entry["status"] == "rejected"

    def test_list_entries_filter_by_status(self, client):
        self._setup_project(client)
        e1 = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "R1", "content": "c1",
        }).json()["id"]
        client.post("/api/entries", json={
            "project_id": "test-proj", "title": "R2", "content": "c2",
        })
        client.post(f"/api/entries/{e1}/approve")
        pending = client.get("/api/entries?project_id=test-proj&status=pending").json()
        indexed = client.get("/api/entries?project_id=test-proj&status=indexed").json()
        assert len(pending) == 1
        assert len(indexed) == 1

    def test_list_entries_filter_by_category(self, client):
        self._setup_project(client)
        client.post("/api/entries", json={
            "project_id": "test-proj", "title": "R1", "content": "c1",
            "category": "business-rule",
        })
        client.post("/api/entries", json={
            "project_id": "test-proj", "title": "R2", "content": "c2",
            "category": "architecture",
        })
        results = client.get("/api/entries?project_id=test-proj&category=business-rule").json()
        assert len(results) == 1
        assert results[0]["title"] == "R1"
