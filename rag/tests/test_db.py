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
