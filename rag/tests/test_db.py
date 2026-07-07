"""Tests for the knowledge base database layer."""
import time
import pytest


class TestSchema:
    def test_init_creates_tables(self, temp_db):
        conn = temp_db.get_connection()
        tables = [r[0] for r in conn.execute(
            "SELECT name FROM sqlite_master WHERE type='table'"
        ).fetchall()]
        conn.close()
        assert "projects" in tables
        assert "knowledge_entries" in tables
        assert "tags" in tables
        assert "entry_tags" in tables

    def test_knowledge_entries_has_required_columns(self, temp_db):
        conn = temp_db.get_connection()
        cols = [r[1] for r in conn.execute("PRAGMA table_info(knowledge_entries)").fetchall()]
        conn.close()
        expected = {"id", "project_id", "title", "content", "category",
                    "source", "author", "status", "metadata", "created_at", "updated_at"}
        assert expected.issubset(set(cols))

    def test_tags_table_columns(self, temp_db):
        conn = temp_db.get_connection()
        cols = [r[1] for r in conn.execute("PRAGMA table_info(tags)").fetchall()]
        conn.close()
        assert "id" in cols
        assert "project_id" in cols
        assert "name" in cols

    def test_entry_tags_table_columns(self, temp_db):
        conn = temp_db.get_connection()
        cols = [r[1] for r in conn.execute("PRAGMA table_info(entry_tags)").fetchall()]
        conn.close()
        assert "entry_id" in cols
        assert "tag_id" in cols

    def test_tags_unique_per_project(self, temp_db):
        temp_db.upsert_project("p1", "P1", "/tmp/p1", "", "")
        conn = temp_db.get_connection()
        conn.execute("INSERT INTO tags (project_id, name) VALUES ('p1', 'auth')")
        with pytest.raises(Exception):
            conn.execute("INSERT INTO tags (project_id, name) VALUES ('p1', 'auth')")
        conn.close()


class TestStoreKnowledge:
    def test_store_entry_basic(self, temp_db):
        temp_db.upsert_project("test-proj", "Test Project", "/tmp/test", "", "")
        entry_id = temp_db.store_knowledge_entry(
            "test-proj", "Order rule", "Orders over 1000 need approval", "business-rule"
        )
        assert entry_id is not None
        assert len(entry_id) > 0

    def test_store_entry_with_tags(self, temp_db):
        temp_db.upsert_project("test-proj", "Test Project", "/tmp/test", "", "")
        entry_id = temp_db.store_knowledge_entry(
            "test-proj", "Auth rule", "Use JWT", "architecture",
            tags=["auth", "security"]
        )
        tags = temp_db.get_tags_for_entry(entry_id)
        assert "auth" in tags
        assert "security" in tags
        assert len(tags) == 2

    def test_store_entry_default_category(self, temp_db):
        temp_db.upsert_project("test-proj", "Test", "/tmp/test", "", "")
        entry_id = temp_db.store_knowledge_entry(
            "test-proj", "Note", "Some content"
        )
        entry = temp_db.get_entry(entry_id)
        assert entry["category"] == "insight"

    def test_store_entry_invalid_category_falls_back(self, temp_db):
        temp_db.upsert_project("test-proj", "Test", "/tmp/test", "", "")
        entry_id = temp_db.store_knowledge_entry(
            "test-proj", "Note", "Content", "nonexistent-category"
        )
        entry = temp_db.get_entry(entry_id)
        assert entry["category"] == "insight"

    def test_store_entry_duplicate_title_raises(self, temp_db):
        temp_db.upsert_project("test-proj", "Test Project", "/tmp/test", "", "")
        temp_db.store_knowledge_entry(
            "test-proj", "Rule A", "content", "insight"
        )
        with pytest.raises(Exception):
            temp_db.store_knowledge_entry(
                "test-proj", "Rule A", "different", "insight"
            )


class TestGetEntry:
    def test_get_entry_returns_full_entry(self, temp_db):
        temp_db.upsert_project("test-proj", "Test", "/tmp/test", "", "")
        eid = temp_db.store_knowledge_entry(
            "test-proj", "Rule", "Content here", "business-rule",
            tags=["orders"]
        )
        entry = temp_db.get_entry(eid)
        assert entry["title"] == "Rule"
        assert entry["content"] == "Content here"
        assert entry["category"] == "business-rule"
        assert "orders" in entry["tags"]

    def test_get_entry_nonexistent(self, temp_db):
        entry = temp_db.get_entry("nonexistent-id")
        assert entry is None


class TestListEntries:
    def test_list_all(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj", "", "")
        temp_db.store_knowledge_entry("proj", "R1", "c1", "insight")
        temp_db.store_knowledge_entry("proj", "R2", "c2", "architecture")
        entries = temp_db.list_entries("proj")
        assert len(entries) == 2

    def test_filter_by_category(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj", "", "")
        temp_db.store_knowledge_entry("proj", "R1", "c1", "business-rule")
        temp_db.store_knowledge_entry("proj", "R2", "c2", "architecture")
        entries = temp_db.list_entries("proj", category="architecture")
        assert len(entries) == 1
        assert entries[0]["title"] == "R2"

    def test_filter_by_tags(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj", "", "")
        temp_db.store_knowledge_entry("proj", "R1", "c1", "insight", tags=["orders"])
        temp_db.store_knowledge_entry("proj", "R2", "c2", "insight", tags=["auth"])
        temp_db.store_knowledge_entry("proj", "R3", "c3", "insight", tags=["auth", "db"])
        entries = temp_db.list_entries("proj", tags=["auth"])
        assert len(entries) == 2

    def test_filter_by_status(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj", "", "")
        eid = temp_db.store_knowledge_entry("proj", "R1", "c1", "insight")
        temp_db.approve_entries([eid])
        temp_db.store_knowledge_entry("proj", "R2", "c2", "insight")
        indexed = temp_db.list_entries("proj", status="indexed")
        pending = temp_db.list_entries("proj", status="pending")
        assert len(indexed) == 1
        assert len(pending) == 1

    def test_list_empty_project(self, temp_db):
        entries = temp_db.list_entries("nonexistent")
        assert entries == []


class TestUpdateEntry:
    def test_update_title(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj", "", "")
        eid = temp_db.store_knowledge_entry("proj", "Old", "Content", "insight")
        temp_db.update_entry(eid, title="New Title")
        entry = temp_db.get_entry(eid)
        assert entry["title"] == "New Title"

    def test_update_content(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj", "", "")
        eid = temp_db.store_knowledge_entry("proj", "Title", "Old content", "insight")
        temp_db.update_entry(eid, content="New content")
        entry = temp_db.get_entry(eid)
        assert entry["content"] == "New content"

    def test_update_category(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj", "", "")
        eid = temp_db.store_knowledge_entry("proj", "Title", "Content", "insight")
        temp_db.update_entry(eid, category="architecture")
        entry = temp_db.get_entry(eid)
        assert entry["category"] == "architecture"

    def test_update_tags(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj", "", "")
        eid = temp_db.store_knowledge_entry("proj", "Title", "Content", "insight", tags=["old"])
        temp_db.update_entry(eid, tags=["new", "updated"])
        tags = temp_db.get_tags_for_entry(eid)
        assert "new" in tags
        assert "updated" in tags
        assert "old" not in tags


class TestRemoveEntry:
    def test_remove_existing(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj", "", "")
        eid = temp_db.store_knowledge_entry("proj", "R1", "c1", "insight")
        temp_db.remove_entry(eid)
        assert temp_db.get_entry(eid) is None

    def test_remove_tags_also_deleted(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj", "", "")
        eid = temp_db.store_knowledge_entry("proj", "R1", "c1", "insight", tags=["orders"])
        temp_db.remove_entry(eid)
        tags = temp_db.get_tags_for_entry(eid)
        assert tags == []


class TestApprovalWorkflow:
    def test_pending_by_default(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj", "", "")
        eid = temp_db.store_knowledge_entry("proj", "R1", "c1", "insight")
        entry = temp_db.get_entry(eid)
        assert entry["status"] == "pending"

    def test_approve_entry(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj", "", "")
        eid = temp_db.store_knowledge_entry("proj", "R1", "c1", "insight")
        temp_db.approve_entries([eid])
        entry = temp_db.get_entry(eid)
        assert entry["status"] == "indexed"

    def test_reject_entry(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj", "", "")
        eid = temp_db.store_knowledge_entry("proj", "R1", "c1", "insight")
        temp_db.reject_entries([eid])
        entry = temp_db.get_entry(eid)
        assert entry["status"] == "rejected"

    def test_approve_all(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj", "", "")
        temp_db.store_knowledge_entry("proj", "R1", "c1", "insight")
        temp_db.store_knowledge_entry("proj", "R2", "c2", "insight")
        temp_db.approve_entries(["__ALL__"])
        entries = temp_db.list_entries("proj", status="pending")
        assert len(entries) == 0

    def test_get_pending_entries(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj", "", "")
        temp_db.store_knowledge_entry("proj", "R1", "c1", "insight")
        temp_db.store_knowledge_entry("proj", "R2", "c2", "insight")
        temp_db.approve_entries(["__ALL__"])
        pending = temp_db.get_pending_entries("proj")
        assert len(pending) == 0

    def test_get_pending_entries_no_project(self, temp_db):
        temp_db.upsert_project("p1", "P1", "/tmp/p1", "", "")
        temp_db.upsert_project("p2", "P2", "/tmp/p2", "", "")
        temp_db.store_knowledge_entry("p1", "R1", "c1", "insight")
        temp_db.store_knowledge_entry("p2", "R2", "c2", "insight")
        all_pending = temp_db.get_pending_entries()
        assert len(all_pending) == 2

    def test_get_indexed_entries_only_indexed(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj", "", "")
        eid1 = temp_db.store_knowledge_entry("proj", "R1", "c1", "insight")
        temp_db.store_knowledge_entry("proj", "R2", "c2", "insight")
        temp_db.approve_entries([eid1])
        indexed = temp_db.get_indexed_entries("proj")
        assert len(indexed) == 1
        assert indexed[0]["title"] == "R1"


class TestProjectOps:
    def test_upsert_and_get(self, temp_db):
        temp_db.upsert_project("test", "Test", "/tmp/test", "desc", "python")
        proj = temp_db.get_project("test")
        assert proj["name"] == "Test"
        assert proj["root_path"] == "/tmp/test"

    def test_get_project_by_path(self, temp_db):
        temp_db.upsert_project("test", "Test", "/tmp/test", "", "")
        proj = temp_db.get_project_by_path("/tmp/test")
        assert proj["id"] == "test"

    def test_get_project_by_path_not_found(self, temp_db):
        proj = temp_db.get_project_by_path("/nonexistent")
        assert proj is None

    def test_list_projects(self, temp_db):
        temp_db.upsert_project("a", "A", "/tmp/a", "", "")
        temp_db.upsert_project("b", "B", "/tmp/b", "", "")
        projects = temp_db.list_projects()
        assert len(projects) >= 2

    def test_project_stats(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj", "", "")
        temp_db.store_knowledge_entry("proj", "R1", "c1", "insight")
        temp_db.store_knowledge_entry("proj", "R2", "c2", "insight", tags=["auth"])
        eid3 = temp_db.store_knowledge_entry("proj", "R3", "c3", "insight")
        temp_db.approve_entries([eid3])
        stats = temp_db.get_project_stats("proj")
        assert stats["total"] == 3
        assert stats["indexed"] == 1
        assert stats["pending"] == 2


class TestTags:
    def test_get_all_tags(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj", "", "")
        temp_db.store_knowledge_entry("proj", "R1", "c1", "insight", tags=["orders"])
        temp_db.store_knowledge_entry("proj", "R2", "c2", "insight", tags=["auth", "orders"])
        all_tags = temp_db.get_all_tags("proj")
        assert "orders" in all_tags
        assert "auth" in all_tags

    def test_get_all_tags_empty(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj", "", "")
        all_tags = temp_db.get_all_tags("proj")
        assert all_tags == []

    def test_tags_are_case_insensitive(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj", "", "")
        temp_db.store_knowledge_entry("proj", "R1", "c1", "insight", tags=["Auth"])
        tags = temp_db.get_all_tags("proj")
        assert "auth" in tags


class TestProjectPaths:
    def test_add_project_path(self, temp_db):
        temp_db.upsert_project("p1", "Proj1", "/tmp/p1")
        temp_db.add_project_path("p1", "/tmp/p1-frontend")
        paths = temp_db.list_project_paths("p1")
        assert "/tmp/p1-frontend" in paths
        assert "/tmp/p1" in paths

    def test_add_duplicate_path_ignored(self, temp_db):
        temp_db.upsert_project("p1", "Proj1", "/tmp/p1")
        temp_db.add_project_path("p1", "/tmp/p1-frontend")
        temp_db.add_project_path("p1", "/tmp/p1-frontend")
        paths = temp_db.list_project_paths("p1")
        assert paths.count("/tmp/p1-frontend") == 1

    def test_remove_project_path(self, temp_db):
        temp_db.upsert_project("p1", "Proj1", "/tmp/p1")
        temp_db.add_project_path("p1", "/tmp/p1-frontend")
        temp_db.remove_project_path("p1", "/tmp/p1-frontend")
        paths = temp_db.list_project_paths("p1")
        assert "/tmp/p1-frontend" not in paths

    def test_remove_last_path_raises(self, temp_db):
        temp_db.upsert_project("p1", "Proj1", "/tmp/p1")
        with pytest.raises(ValueError, match="last"):
            temp_db.remove_project_path("p1", "/tmp/p1")

    def test_get_project_by_path_finds_additional_path(self, temp_db):
        temp_db.upsert_project("p1", "Proj1", "/tmp/p1")
        temp_db.add_project_path("p1", "/tmp/p1-frontend")
        project = temp_db.get_project_by_path("/tmp/p1-frontend")
        assert project is not None
        assert project["id"] == "p1"

    def test_list_projects_includes_paths(self, temp_db):
        temp_db.upsert_project("p1", "Proj1", "/tmp/p1")
        temp_db.add_project_path("p1", "/tmp/p1-frontend")
        projects = temp_db.list_projects()
        assert "paths" in projects[0]
        assert "/tmp/p1" in projects[0]["paths"]
        assert "/tmp/p1-frontend" in projects[0]["paths"]

    def test_upsert_project_ensures_root_path_in_project_paths(self, temp_db):
        temp_db.upsert_project("p1", "Proj1", "/tmp/p1")
        paths = temp_db.list_project_paths("p1")
        assert "/tmp/p1" in paths


class TestGraph:
    def test_upsert_entity_dedupes_by_normalized_name(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj")
        id1 = temp_db.upsert_entity("proj", "  Pedido ")
        id2 = temp_db.upsert_entity("proj", "pedido")
        assert id1 == id2

    def test_upsert_entity_backfills_empty_type(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj")
        eid = temp_db.upsert_entity("proj", "Pedido")
        temp_db.upsert_entity("proj", "pedido", type="concept")
        conn = temp_db.get_connection()
        row = conn.execute("SELECT type FROM entities WHERE id = ?", (eid,)).fetchone()
        conn.close()
        assert row["type"] == "concept"

    def test_upsert_entity_does_not_overwrite_existing_type(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj")
        eid = temp_db.upsert_entity("proj", "Pedido", type="concept")
        temp_db.upsert_entity("proj", "pedido", type="other")
        conn = temp_db.get_connection()
        row = conn.execute("SELECT type FROM entities WHERE id = ?", (eid,)).fetchone()
        conn.close()
        assert row["type"] == "concept"

    def test_add_relation_creates_entities_on_the_fly(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj")
        rel_id = temp_db.add_relation("proj", "Pedido", "requer", "Aprovacao")
        assert rel_id is not None
        conn = temp_db.get_connection()
        count = conn.execute(
            "SELECT COUNT(*) as c FROM entities WHERE project_id = 'proj'"
        ).fetchone()["c"]
        conn.close()
        assert count == 2

    def test_add_relation_duplicate_not_duplicated(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj")
        temp_db.add_relation("proj", "Pedido", "requer", "Aprovacao")
        temp_db.add_relation("proj", "Pedido", "requer", "Aprovacao")
        conn = temp_db.get_connection()
        count = conn.execute(
            "SELECT COUNT(*) as c FROM relations WHERE project_id = 'proj'"
        ).fetchone()["c"]
        conn.close()
        assert count == 1

    def test_add_relation_with_entry_id_links_both_entities(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj")
        eid = temp_db.store_knowledge_entry("proj", "Rule", "content", "business-rule")
        temp_db.add_relation("proj", "Pedido", "requer", "Aprovacao", entry_id=eid)
        entities = temp_db.get_entities_for_entry(eid)
        names = {e["name"].lower() for e in entities}
        assert "pedido" in names
        assert "aprovacao" in names

    def test_get_relations_for_entry_returns_name_based_triples(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj")
        eid = temp_db.store_knowledge_entry("proj", "Rule", "content", "business-rule")
        temp_db.add_relation("proj", "Pedido", "requer", "Aprovacao", entry_id=eid)
        rels = temp_db.get_relations_for_entry(eid)
        assert len(rels) == 1
        assert rels[0]["subject"].lower() == "pedido"
        assert rels[0]["predicate"] == "requer"
        assert rels[0]["object"].lower() == "aprovacao"

    def test_add_entry_link_idempotent_and_visible_from_both_sides(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj")
        e1 = temp_db.store_knowledge_entry("proj", "R1", "c1", "insight")
        e2 = temp_db.store_knowledge_entry("proj", "R2", "c2", "insight")
        temp_db.add_entry_link(e1, e2)
        temp_db.add_entry_link(e1, e2)
        links_from_e1 = temp_db.get_entry_links(e1)
        links_from_e2 = temp_db.get_entry_links(e2)
        assert len(links_from_e1) == 1
        assert len(links_from_e2) == 1

    def test_get_graph_returns_entities_and_relations_with_entry_count(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj")
        eid = temp_db.store_knowledge_entry("proj", "Rule", "content", "business-rule")
        temp_db.add_relation("proj", "Pedido", "requer", "Aprovacao", entry_id=eid)
        graph = temp_db.get_graph("proj")
        assert len(graph["entities"]) == 2
        assert len(graph["relations"]) == 1
        pedido = next(e for e in graph["entities"] if e["name"].lower() == "pedido")
        assert pedido["entry_count"] == 1

    def test_query_entity_graph_depth1_direct_neighbors(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj")
        e1 = temp_db.store_knowledge_entry("proj", "R1", "c1", "insight")
        temp_db.approve_entries([e1])
        temp_db.add_relation("proj", "Pedido", "requer", "Aprovacao", entry_id=e1)
        result = temp_db.query_entity_graph("proj", "pedido", depth=1)
        assert result["entity"] is not None
        assert len(result["triples"]) == 1
        assert result["triples"][0]["object"].lower() == "aprovacao"
        entry_ids = {e["id"] for e in result["entries"]}
        assert e1 in entry_ids

    def test_query_entity_graph_entries_only_indexed(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj")
        e1 = temp_db.store_knowledge_entry("proj", "R1", "c1", "insight")
        e2 = temp_db.store_knowledge_entry("proj", "R2", "c2", "insight")
        e3 = temp_db.store_knowledge_entry("proj", "R3", "c3", "insight")
        temp_db.approve_entries([e1])
        temp_db.reject_entries([e3])
        aid = temp_db.upsert_entity("proj", "Pedido")
        temp_db.link_entry_entity(e1, aid)
        temp_db.link_entry_entity(e2, aid)  # pending
        temp_db.link_entry_entity(e3, aid)  # rejected
        result = temp_db.query_entity_graph("proj", "pedido", depth=1)
        entry_ids = {e["id"] for e in result["entries"]}
        assert e1 in entry_ids
        assert e2 not in entry_ids
        assert e3 not in entry_ids

    def test_query_entity_graph_traverses_from_object_side(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj")
        temp_db.add_relation("proj", "Pedido", "requer", "Aprovacao")
        result = temp_db.query_entity_graph("proj", "aprovacao", depth=1)
        assert result["entity"] is not None
        assert len(result["triples"]) == 1
        assert result["triples"][0]["subject"].lower() == "pedido"

    def test_query_entity_graph_unknown_entity(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj")
        result = temp_db.query_entity_graph("proj", "does-not-exist")
        assert result["entity"] is None
        assert result["triples"] == []
        assert result["entries"] == []

    def test_expand_entries_via_graph_finds_connected_indexed_entry(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj")
        e1 = temp_db.store_knowledge_entry("proj", "E1", "c1", "insight")
        e2 = temp_db.store_knowledge_entry("proj", "E2", "c2", "insight")
        e3 = temp_db.store_knowledge_entry("proj", "E3", "c3", "insight")
        temp_db.approve_entries([e1, e2])
        temp_db.reject_entries([e3])
        temp_db.link_entry_entity(e1, temp_db.upsert_entity("proj", "A"))
        temp_db.link_entry_entity(e2, temp_db.upsert_entity("proj", "B"))
        temp_db.link_entry_entity(e3, temp_db.upsert_entity("proj", "B"))
        temp_db.add_relation("proj", "A", "relates_to", "B")

        result = temp_db.expand_entries_via_graph("proj", [e1], depth=1)
        related_ids = {e["id"] for e in result["related_entries"]}
        assert e2 in related_ids
        assert e3 not in related_ids
        assert e1 not in related_ids
        assert len(result["triples"]) == 1

    def test_expand_entries_via_graph_depth2_reaches_further_entry(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj")
        e1 = temp_db.store_knowledge_entry("proj", "E1", "c1", "insight")
        e2 = temp_db.store_knowledge_entry("proj", "E2", "c2", "insight")
        temp_db.approve_entries([e1, e2])
        temp_db.link_entry_entity(e1, temp_db.upsert_entity("proj", "A"))
        temp_db.link_entry_entity(e2, temp_db.upsert_entity("proj", "C"))
        temp_db.add_relation("proj", "A", "rel", "B")
        temp_db.add_relation("proj", "B", "rel", "C")

        depth1 = temp_db.expand_entries_via_graph("proj", [e1], depth=1)
        depth2 = temp_db.expand_entries_via_graph("proj", [e1], depth=2)
        depth1_ids = {e["id"] for e in depth1["related_entries"]}
        depth2_ids = {e["id"] for e in depth2["related_entries"]}
        assert e2 not in depth1_ids
        assert e2 in depth2_ids

    def test_expand_entries_via_graph_traverses_from_object_side(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj")
        e1 = temp_db.store_knowledge_entry("proj", "E1", "c1", "insight")
        e2 = temp_db.store_knowledge_entry("proj", "E2", "c2", "insight")
        temp_db.approve_entries([e1, e2])
        # Seed entity B sits on the OBJECT side of the relation A -> B
        temp_db.link_entry_entity(e1, temp_db.upsert_entity("proj", "B"))
        temp_db.link_entry_entity(e2, temp_db.upsert_entity("proj", "A"))
        temp_db.add_relation("proj", "A", "rel", "B")

        result = temp_db.expand_entries_via_graph("proj", [e1], depth=1)
        related_ids = {e["id"] for e in result["related_entries"]}
        assert e2 in related_ids
        assert len(result["triples"]) == 1

    def test_expand_entries_via_graph_includes_entry_links_neighbors(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj")
        e1 = temp_db.store_knowledge_entry("proj", "E1", "c1", "insight")
        e2 = temp_db.store_knowledge_entry("proj", "E2", "c2", "insight")
        e3 = temp_db.store_knowledge_entry("proj", "E3", "c3", "insight")
        temp_db.approve_entries([e1, e2, e3])
        # No entities/relations at all: only direct entry links, both directions
        temp_db.add_entry_link(e1, e2)  # seed on the from side
        temp_db.add_entry_link(e3, e1)  # seed on the to side

        result = temp_db.expand_entries_via_graph("proj", [e1], depth=1)
        related_ids = {e["id"] for e in result["related_entries"]}
        assert e2 in related_ids
        assert e3 in related_ids
        assert e1 not in related_ids

    def test_expand_entries_via_graph_includes_relation_provenance_entry(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj")
        e1 = temp_db.store_knowledge_entry("proj", "E1", "c1", "insight")
        e2 = temp_db.store_knowledge_entry("proj", "E2", "c2", "insight")
        temp_db.approve_entries([e1, e2])
        temp_db.link_entry_entity(e1, temp_db.upsert_entity("proj", "A"))
        # Relation carries e2 as provenance; strip e2's entry_entities rows so
        # it is only discoverable via the relations.entry_id branch.
        temp_db.add_relation("proj", "A", "rel", "B", entry_id=e2)
        conn = temp_db.get_connection()
        conn.execute("DELETE FROM entry_entities WHERE entry_id = ?", (e2,))
        conn.commit()
        conn.close()

        result = temp_db.expand_entries_via_graph("proj", [e1], depth=1)
        related_ids = {e["id"] for e in result["related_entries"]}
        assert e2 in related_ids

    def test_cascade_delete_entry_removes_relations_and_entry_entities(self, temp_db):
        temp_db.upsert_project("proj", "Proj", "/tmp/proj")
        eid = temp_db.store_knowledge_entry("proj", "Rule", "content", "business-rule")
        temp_db.add_relation("proj", "Pedido", "requer", "Aprovacao", entry_id=eid)
        temp_db.remove_entry(eid)
        conn = temp_db.get_connection()
        rel_count = conn.execute(
            "SELECT COUNT(*) as c FROM relations WHERE entry_id = ?", (eid,)
        ).fetchone()["c"]
        ee_count = conn.execute(
            "SELECT COUNT(*) as c FROM entry_entities WHERE entry_id = ?", (eid,)
        ).fetchone()["c"]
        conn.close()
        assert rel_count == 0
        assert ee_count == 0


class TestMigrationFramework:
    def test_fresh_db_is_stamped_with_latest_version(self, temp_db):
        conn = temp_db.get_connection()
        try:
            version = conn.execute("PRAGMA user_version").fetchone()[0]
        finally:
            conn.close()
        assert version == len(temp_db.MIGRATIONS)
        assert version >= 3

    def test_init_db_is_idempotent(self, temp_db):
        temp_db.init_db()
        temp_db.init_db()
        conn = temp_db.get_connection()
        try:
            version = conn.execute("PRAGMA user_version").fetchone()[0]
        finally:
            conn.close()
        assert version == len(temp_db.MIGRATIONS)

    def test_legacy_db_without_language_column_is_migrated(self, monkeypatch, tmp_path):
        import importlib
        import db as db_mod
        db_path = str(tmp_path / "legacy.db")
        monkeypatch.setattr(db_mod, "DB_PATH", db_path)
        monkeypatch.setattr(db_mod, "DATA_DIR", str(tmp_path))
        # Simulate a pre-language, pre-project_paths database at user_version 0
        conn = db_mod.get_connection()
        try:
            conn.executescript("""
                CREATE TABLE projects (
                    id TEXT PRIMARY KEY,
                    name TEXT NOT NULL,
                    root_path TEXT NOT NULL,
                    description TEXT DEFAULT '',
                    project_type TEXT DEFAULT '',
                    created_at REAL NOT NULL,
                    updated_at REAL NOT NULL
                );
            """)
            conn.execute(
                "INSERT INTO projects (id, name, root_path, created_at, updated_at) VALUES ('p1', 'P1', '/tmp/p1', 1, 1)"
            )
            conn.commit()
        finally:
            conn.close()

        db_mod.init_db()

        conn = db_mod.get_connection()
        try:
            cols = [r[1] for r in conn.execute("PRAGMA table_info(projects)").fetchall()]
            assert "language" in cols
            paths = conn.execute(
                "SELECT path FROM project_paths WHERE project_id = 'p1'"
            ).fetchall()
            assert [r["path"] for r in paths] == ["/tmp/p1"]
            version = conn.execute("PRAGMA user_version").fetchone()[0]
            assert version == len(db_mod.MIGRATIONS)
        finally:
            conn.close()


class TestBatchTags:
    def test_tags_for_entries_batches(self, temp_db):
        temp_db.upsert_project("p1", "P1", "/tmp/p1")
        e1 = temp_db.store_knowledge_entry("p1", "T1", "c", tags=["a", "b"])
        e2 = temp_db.store_knowledge_entry("p1", "T2", "c", tags=["b"])
        e3 = temp_db.store_knowledge_entry("p1", "T3", "c")
        conn = temp_db.get_connection()
        try:
            tags_map = temp_db._tags_for_entries(conn, [e1, e2, e3])
        finally:
            conn.close()
        assert tags_map[e1] == ["a", "b"]
        assert tags_map[e2] == ["b"]
        assert tags_map[e3] == []

    def test_tags_for_entries_empty_input(self, temp_db):
        conn = temp_db.get_connection()
        try:
            assert temp_db._tags_for_entries(conn, []) == {}
        finally:
            conn.close()


class TestEmbeddingCleanup:
    def test_remove_entry_deletes_embedding(self, temp_db):
        import embeddings
        temp_db.upsert_project("p1", "P1", "/tmp/p1")
        eid = temp_db.store_knowledge_entry("p1", "T1", "content")
        temp_db.store_entry_embeddings(eid, "p1", [embeddings.embed_text("content")])
        temp_db.remove_entry(eid)
        assert temp_db.search_chunks(embeddings.embed_query("content"), project_id="p1", k=5) == []

    def test_remove_project_deletes_entry_embeddings(self, temp_db):
        import embeddings
        temp_db.upsert_project("p1", "P1", "/tmp/p1")
        eid = temp_db.store_knowledge_entry("p1", "T1", "content")
        temp_db.store_entry_embeddings(eid, "p1", [embeddings.embed_text("content")])
        temp_db.remove_project("p1")
        assert temp_db.get_project("p1") is None
        assert temp_db.get_entry(eid) is None
        assert temp_db.search_chunks(embeddings.embed_query("content"), project_id="p1", k=5) == []

    def test_migration_purges_orphan_embeddings_legacy_table(self, temp_db):
        conn = temp_db.get_connection()
        try:
            dim = 384
            conn.execute(f"""
                CREATE VIRTUAL TABLE IF NOT EXISTS entry_embeddings USING vec0(
                    entry_id TEXT PRIMARY KEY,
                    embedding FLOAT[{dim}]
                )
            """)
            import struct
            conn.execute(
                "INSERT INTO entry_embeddings (entry_id, embedding) VALUES (?, ?)",
                ("ghost", struct.pack(f"{dim}f", *([0.1] * dim))),
            )
            conn.execute("PRAGMA user_version = 3")
            conn.commit()
        finally:
            conn.close()
        temp_db.init_db()
        conn = temp_db.get_connection()
        try:
            tables = {r[0] for r in conn.execute(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'entry_embeddings'"
            ).fetchall()}
        finally:
            conn.close()
        assert tables == set()  # migration 0005 dropped the legacy table
