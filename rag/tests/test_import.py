"""Tests for document import (markdown/text parsing)."""
import os
import tempfile
import pytest
from doc_import import parse_markdown, parse_text_file, import_document


class TestParseMarkdown:
    def test_single_section(self):
        md = "# Order Approval Rule\n\nOrders over 1000 need manager approval."
        entries = parse_markdown(md)
        assert len(entries) == 1
        assert entries[0]["title"] == "Order Approval Rule"
        assert "1000" in entries[0]["content"]

    def test_multiple_sections(self):
        md = """# Auth Architecture

We use JWT with refresh tokens.

# Database Choice

Postgres for relational data."""
        entries = parse_markdown(md)
        assert len(entries) == 2
        assert entries[0]["title"] == "Auth Architecture"
        assert entries[1]["title"] == "Database Choice"

    def test_h2_headers(self):
        md = """## Section A

Content A

## Section B

Content B"""
        entries = parse_markdown(md)
        assert len(entries) == 2
        assert entries[0]["title"] == "Section A"

    def test_frontmatter(self):
        md = """---
category: business-rule
tags: orders, approval
---

# Order Rule

Orders over 1000 need approval."""
        entries = parse_markdown(md)
        assert len(entries) == 1
        assert entries[0]["category"] == "business-rule"
        assert "orders" in entries[0]["tags"]
        assert "approval" in entries[0]["tags"]

    def test_no_headers(self):
        md = "Just some text without headers."
        entries = parse_markdown(md)
        assert len(entries) == 1
        assert entries[0]["title"] == "Untitled"

    def test_empty_content_skipped(self):
        md = "# Header 1\n\n# Header 2\n\nContent here."
        entries = parse_markdown(md)
        assert len(entries) == 1
        assert entries[0]["title"] == "Header 2"


class TestParseTextFile:
    def test_plain_text(self):
        entries = parse_text_file("Just a plain text note about something.")
        assert len(entries) == 1
        assert entries[0]["title"] == "Untitled"
        assert "plain text" in entries[0]["content"]


class TestImportDocument:
    def test_import_markdown_file(self, temp_db):
        temp_db.upsert_project("test-proj", "Test", "/tmp/test", "", "")
        with tempfile.NamedTemporaryFile(mode="w", suffix=".md", delete=False) as f:
            f.write("# Test Rule\n\nSome content here.")
            f.flush()
            filepath = f.name
        try:
            entry_ids = import_document(temp_db, "test-proj", filepath, category="insight")
            assert len(entry_ids) == 1
            entry = temp_db.get_entry(entry_ids[0])
            assert entry["title"] == "Test Rule"
            assert entry["source"] == "import"
        finally:
            os.unlink(filepath)

    def test_import_txt_file(self, temp_db):
        temp_db.upsert_project("test-proj", "Test", "/tmp/test", "", "")
        with tempfile.NamedTemporaryFile(mode="w", suffix=".txt", delete=False) as f:
            f.write("Just a plain text note.")
            f.flush()
            filepath = f.name
        try:
            entry_ids = import_document(temp_db, "test-proj", filepath)
            assert len(entry_ids) == 1
            entry = temp_db.get_entry(entry_ids[0])
            assert entry["source"] == "import"
        finally:
            os.unlink(filepath)

    def test_import_nonexistent_file_raises(self, temp_db):
        temp_db.upsert_project("test-proj", "Test", "/tmp/test", "", "")
        with pytest.raises(FileNotFoundError):
            import_document(temp_db, "test-proj", "/nonexistent/file.md")
