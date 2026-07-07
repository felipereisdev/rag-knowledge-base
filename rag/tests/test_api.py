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


class TestGraph:
    def _setup_project(self, client, pid="test-proj"):
        client.post("/api/projects", json={
            "id": pid, "name": "Test", "root_path": "/tmp/test",
        })

    def test_get_graph_not_found(self, client):
        resp = client.get("/api/graph?project_id=nonexistent")
        assert resp.status_code == 404

    def test_get_graph_empty(self, client):
        self._setup_project(client)
        resp = client.get("/api/graph?project_id=test-proj")
        assert resp.status_code == 200
        data = resp.json()
        assert data["entities"] == []
        assert data["relations"] == []

    def test_get_graph_with_data(self, client):
        self._setup_project(client)
        client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Rule", "content": "content",
            "entities": [{"name": "Order", "type": "concept"}, {"name": "Manager", "type": "role"}],
            "relations": [{"subject": "Order", "predicate": "requires", "object": "Manager"}],
        })
        resp = client.get("/api/graph?project_id=test-proj")
        assert resp.status_code == 200
        data = resp.json()
        assert len(data["entities"]) == 2
        assert len(data["relations"]) == 1

    def test_get_entity_graph_project_not_found(self, client):
        resp = client.get("/api/graph/entity?project_id=nonexistent&name=Order")
        assert resp.status_code == 404

    def test_get_entity_graph_unknown_entity(self, client):
        self._setup_project(client)
        resp = client.get("/api/graph/entity?project_id=test-proj&name=Ghost")
        assert resp.status_code == 200
        data = resp.json()
        assert data["entity"] is None
        assert data["triples"] == []
        assert data["entries"] == []

    def test_get_entity_graph_found(self, client):
        self._setup_project(client)
        client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Rule", "content": "content",
            "entities": [{"name": "Order"}, {"name": "Manager"}],
            "relations": [{"subject": "Order", "predicate": "requires", "object": "Manager"}],
        })
        resp = client.get("/api/graph/entity?project_id=test-proj&name=order&depth=1")
        assert resp.status_code == 200
        data = resp.json()
        assert data["entity"]["name"] == "Order"
        assert len(data["triples"]) == 1

    def test_get_entry_graph_not_found(self, client):
        resp = client.get("/api/entries/nonexistent-id/graph")
        assert resp.status_code == 404

    def test_get_entry_graph(self, client):
        self._setup_project(client)
        create = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Rule", "content": "content",
            "entities": [{"name": "Order"}, {"name": "Manager"}],
            "relations": [{"subject": "Order", "predicate": "requires", "object": "Manager"}],
        }).json()
        eid = create["id"]
        resp = client.get(f"/api/entries/{eid}/graph")
        assert resp.status_code == 200
        data = resp.json()
        assert len(data["entities"]) == 2
        assert len(data["relations"]) == 1
        assert data["relations"][0]["subject"] == "Order"
        assert data["relations"][0]["object"] == "Manager"
        assert "links" in data

    def test_entry_update_replaces_relations(self, client):
        self._setup_project(client)
        create = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Rule", "content": "content",
            "entities": [{"name": "Order"}, {"name": "Manager"}],
            "relations": [{"subject": "Order", "predicate": "requires", "object": "Manager"}],
        }).json()
        eid = create["id"]
        resp = client.put(f"/api/entries/{eid}", json={
            "entities": [{"name": "Order"}, {"name": "Director"}],
            "relations": [{"subject": "Order", "predicate": "requires", "object": "Director"}],
        })
        assert resp.status_code == 200
        graph = client.get(f"/api/entries/{eid}/graph").json()
        assert len(graph["relations"]) == 1
        assert graph["relations"][0]["object"] == "Director"
        entity_names = {e["name"] for e in graph["entities"]}
        assert entity_names == {"Order", "Director"}

    def test_entry_update_empty_relations_wipes_graph(self, client):
        self._setup_project(client)
        create = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Rule", "content": "content",
            "entities": [{"name": "Order"}, {"name": "Manager"}],
            "relations": [{"subject": "Order", "predicate": "requires", "object": "Manager"}],
        }).json()
        eid = create["id"]
        resp = client.put(f"/api/entries/{eid}", json={"relations": []})
        assert resp.status_code == 200
        graph = client.get(f"/api/entries/{eid}/graph").json()
        assert graph["relations"] == []
        assert graph["entities"] == []

    def test_entry_update_entities_only_is_additive(self, client):
        self._setup_project(client)
        create = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Rule", "content": "content",
            "entities": [{"name": "Order"}, {"name": "Manager"}],
            "relations": [{"subject": "Order", "predicate": "requires", "object": "Manager"}],
        }).json()
        eid = create["id"]
        resp = client.put(f"/api/entries/{eid}", json={
            "entities": [{"name": "Invoice"}],
        })
        assert resp.status_code == 200
        graph = client.get(f"/api/entries/{eid}/graph").json()
        assert len(graph["relations"]) == 1
        assert graph["relations"][0]["subject"] == "Order"
        assert graph["relations"][0]["object"] == "Manager"
        entity_names = {e["name"] for e in graph["entities"]}
        assert entity_names == {"Order", "Manager", "Invoice"}


class TestSearch:
    def _setup_with_data(self, client):
        client.post("/api/projects", json={
            "id": "test-proj", "name": "Test", "root_path": "/tmp/test",
        })
        e1 = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Order approval rule",
            "content": "Orders over 1000 need manager approval",
            "category": "business-rule", "tags": ["orders"],
        }).json()["id"]
        e2 = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Auth architecture",
            "content": "JWT with refresh tokens in Redis",
            "category": "architecture", "tags": ["auth"],
        }).json()["id"]
        client.post(f"/api/entries/{e1}/approve")
        client.post(f"/api/entries/{e2}/approve")

    def test_search_returns_results(self, client):
        self._setup_with_data(client)
        resp = client.get("/api/search?q=order+approval&project_id=test-proj")
        assert resp.status_code == 200
        data = resp.json()
        assert len(data) >= 1
        assert data[0]["title"] == "Order approval rule"

    def test_search_without_expand_returns_bare_list(self, client):
        self._setup_with_data(client)
        resp = client.get("/api/search?q=order+approval&project_id=test-proj")
        assert resp.status_code == 200
        assert isinstance(resp.json(), list)

    def test_search_with_expand_returns_graph(self, client):
        client.post("/api/projects", json={
            "id": "test-proj", "name": "Test", "root_path": "/tmp/test",
        })
        e1 = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Discount rule",
            "content": "Discounts need manager approval before applying",
            "category": "business-rule",
            "entities": [{"name": "Discount"}],
            "relations": [{"subject": "Discount", "predicate": "requires", "object": "Approval"}],
        }).json()["id"]
        e2 = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Approval process",
            "content": "Manager approval workflow for purchases",
            "category": "documentation",
            "entities": [{"name": "Approval"}],
        }).json()["id"]
        client.post(f"/api/entries/{e1}/approve")
        client.post(f"/api/entries/{e2}/approve")
        resp = client.get("/api/search?q=discount&project_id=test-proj&expand=true&top_k=1")
        assert resp.status_code == 200
        data = resp.json()
        assert "results" in data
        assert "graph" in data
        assert any(t["subject"] == "Discount" for t in data["graph"]["triples"])
        related_titles = [e["title"] for e in data["graph"]["related_entries"]]
        assert "Approval process" in related_titles

    def test_search_no_results(self, client):
        self._setup_with_data(client)
        resp = client.get("/api/search?q=nonexistent&project_id=test-proj")
        assert resp.status_code == 200
        assert resp.json() == []

    def test_search_filter_by_category(self, client):
        self._setup_with_data(client)
        resp = client.get("/api/search?q=approval&project_id=test-proj&category=architecture")
        assert resp.status_code == 200
        for result in resp.json():
            assert result["category"] == "architecture"

    def test_search_top_k(self, client):
        self._setup_with_data(client)
        resp = client.get("/api/search?q=approval&project_id=test-proj&top_k=1")
        assert resp.status_code == 200
        assert len(resp.json()) <= 1


class TestTags:
    def test_list_tags(self, client):
        client.post("/api/projects", json={
            "id": "test-proj", "name": "Test", "root_path": "/tmp/test",
        })
        client.post("/api/entries", json={
            "project_id": "test-proj", "title": "R1", "content": "c1",
            "tags": ["auth", "security"],
        })
        resp = client.get("/api/tags?project_id=test-proj")
        assert resp.status_code == 200
        tags = resp.json()
        assert "auth" in tags
        assert "security" in tags

    def test_list_tags_empty(self, client):
        client.post("/api/projects", json={
            "id": "test-proj", "name": "Test", "root_path": "/tmp/test",
        })
        resp = client.get("/api/tags?project_id=test-proj")
        assert resp.status_code == 200
        assert resp.json() == []


class TestEmbeddingLifecycle:
    def test_approve_generates_embedding(self, client):
        client.post("/api/projects", json={
            "id": "test-proj", "name": "Test", "root_path": "/tmp/test",
        })
        create = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Rule", "content": "content",
        }).json()
        eid = create["id"]
        client.post(f"/api/entries/{eid}/approve")
        import db, embeddings
        hits = db.search_chunks(embeddings.embed_query("content"), project_id="test-proj", k=5)
        assert any(h["entry_id"] == eid for h in hits)

    def test_update_regenerates_embedding(self, client):
        client.post("/api/projects", json={
            "id": "test-proj", "name": "Test", "root_path": "/tmp/test",
        })
        create = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Rule", "content": "content",
        }).json()
        eid = create["id"]
        client.post(f"/api/entries/{eid}/approve")
        import db
        conn = db.get_connection()
        try:
            before = conn.execute(
                "SELECT embedding FROM chunk_embeddings WHERE entry_id = ?", (eid,)
            ).fetchone()["embedding"]
        finally:
            conn.close()
        client.put(f"/api/entries/{eid}", json={"title": "New Title", "content": "new content"})
        conn = db.get_connection()
        try:
            after = conn.execute(
                "SELECT embedding FROM chunk_embeddings WHERE entry_id = ?", (eid,)
            ).fetchone()["embedding"]
        finally:
            conn.close()
        assert after != before

    def test_delete_removes_embedding(self, client):
        client.post("/api/projects", json={
            "id": "test-proj", "name": "Test", "root_path": "/tmp/test",
        })
        create = client.post("/api/entries", json={
            "project_id": "test-proj", "title": "Rule", "content": "content",
        }).json()
        eid = create["id"]
        client.post(f"/api/entries/{eid}/approve")
        client.delete(f"/api/entries/{eid}")
        import db, embeddings
        hits = db.search_chunks(embeddings.embed_query("content"), project_id="test-proj", k=5)
        assert hits == []


class TestProjectPaths:
    def _setup_project(self, client):
        client.post("/api/projects", json={
            "id": "test-proj", "name": "Test", "root_path": "/tmp/test",
        })

    def test_list_paths(self, client):
        self._setup_project(client)
        resp = client.get("/api/projects/test-proj/paths")
        assert resp.status_code == 200
        paths = resp.json()
        assert "/tmp/test" in paths

    def test_add_path(self, client):
        self._setup_project(client)
        resp = client.post("/api/projects/test-proj/paths", json={"path": "/tmp/frontend"})
        assert resp.status_code == 200
        assert "/tmp/frontend" in resp.json()["paths"]

    def test_add_duplicate_path(self, client):
        self._setup_project(client)
        client.post("/api/projects/test-proj/paths", json={"path": "/tmp/frontend"})
        resp = client.post("/api/projects/test-proj/paths", json={"path": "/tmp/frontend"})
        assert resp.status_code == 200
        paths = resp.json()["paths"]
        assert paths.count("/tmp/frontend") == 1

    def test_remove_path(self, client):
        self._setup_project(client)
        client.post("/api/projects/test-proj/paths", json={"path": "/tmp/frontend"})
        resp = client.delete("/api/projects/test-proj/paths?path=/tmp/frontend")
        assert resp.status_code == 204
        paths = client.get("/api/projects/test-proj/paths").json()
        assert "/tmp/frontend" not in paths

    def test_remove_last_path_returns_400(self, client):
        self._setup_project(client)
        resp = client.delete("/api/projects/test-proj/paths?path=/tmp/test")
        assert resp.status_code == 400

    def test_project_response_includes_paths(self, client):
        self._setup_project(client)
        resp = client.get("/api/projects/test-proj")
        assert resp.status_code == 200
        assert "paths" in resp.json()
        assert "/tmp/test" in resp.json()["paths"]

    def test_list_projects_includes_paths(self, client):
        self._setup_project(client)
        resp = client.get("/api/projects")
        assert resp.status_code == 200
        assert "paths" in resp.json()[0]
