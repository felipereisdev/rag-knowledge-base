"""Tests for the FastMCP tool registration and wrappers."""
import pytest


EXPECTED_TOOLS = {
    "rag_store_knowledge", "rag_import_document", "rag_search",
    "rag_list_knowledge", "rag_remove_knowledge", "rag_open_approval_ui",
    "rag_status", "rag_list_projects", "rag_set_language",
    "rag_query_graph", "rag_add_project_path",
}


class TestToolRegistration:
    def test_all_tools_registered(self, temp_db):
        import main
        import anyio
        tools = anyio.run(main.mcp.list_tools)
        assert {t.name for t in tools} == EXPECTED_TOOLS

    def test_tool_descriptions_present(self, temp_db):
        import main
        import anyio
        tools = anyio.run(main.mcp.list_tools)
        by_name = {t.name: t for t in tools}
        assert "approval workflow" in by_name["rag_store_knowledge"].description
        assert "knowledge graph" in by_name["rag_search"].description


class TestToolWrappers:
    def test_store_and_search_roundtrip(self, temp_db, monkeypatch):
        import main
        monkeypatch.setattr("os.getcwd", lambda: "/tmp/toolproj")
        temp_db.upsert_project("toolproj", "toolproj", "/tmp/toolproj")

        out = main.store_knowledge(
            title="Order rule", content="Orders over 1000 need approval",
            category="business-rule", project_id="toolproj",
        )
        assert "pending" in out

        entries = temp_db.get_pending_entries("toolproj")
        assert len(entries) == 1
        temp_db.approve_entries([entries[0]["id"]])
        import indexing
        indexing.index_entry(temp_db.get_entry(entries[0]["id"]))

        out = main.search(query="order approval", project_id="toolproj")
        assert "Order rule" in out
