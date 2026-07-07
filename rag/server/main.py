#!/usr/bin/env python3
"""MCP server for Knowledge Base RAG plugin.

Implements the Model Context Protocol (JSON-RPC 2.0 over stdio) for Codex.
Provides tools for storing and searching knowledge entries with approval workflow.
"""

import os
import sys
import re

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import db
import embeddings
import doc_import
import api

from mcp.server.fastmcp import FastMCP
from api import EntityIn, RelationIn

SERVER_NAME = "rag"
SERVER_VERSION = "0.3.0"


def log(msg):
    sys.stderr.write(f"[rag] {msg}\n")
    sys.stderr.flush()


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _slugify(text):
    text = text.lower().strip()
    text = re.sub(r"[^a-z0-9]+", "-", text)
    text = text.strip("-")
    return text or "project"


def _project_id_from_path(root_path):
    base = os.path.basename(os.path.abspath(root_path))
    return _slugify(base)


def _resolve_project_id(args):
    """Resolve project_id from args or current directory."""
    pid = args.get("project_id")
    if pid:
        return pid
    cwd = os.getcwd()
    project = db.get_project_by_path(cwd)
    if project:
        return project["id"]
    return _project_id_from_path(cwd)


def _ensure_project(args):
    """Ensure a project exists, creating it if needed. Returns project_id."""
    pid = _resolve_project_id(args)
    project = db.get_project(pid)
    if not project:
        root_path = args.get("root_path", os.getcwd())
        name = args.get("project_name", os.path.basename(os.path.abspath(root_path)))
        db.upsert_project(pid, name, root_path, "", "")
    return pid


def _coerce_depth(value, default=1, max_depth=2):
    """Coerce a graph depth argument to an int clamped to [1, max_depth]."""
    try:
        depth = int(float(value))
    except (TypeError, ValueError):
        depth = default
    if depth < 1:
        return 1
    if depth > max_depth:
        return max_depth
    return depth


def _valid_graph_items(items, required_keys):
    """Split graph payload items into (valid, skipped_count).

    An item is valid when it is a dict with a non-empty string for every
    required key. Malformed items are skipped, never raised on.
    """
    if items is None:
        return [], 0
    if not isinstance(items, list):
        return [], 1
    valid = []
    skipped = 0
    for item in items:
        if isinstance(item, dict) and all(
            isinstance(item.get(k), str) and item[k].strip() for k in required_keys
        ):
            valid.append(item)
        else:
            skipped += 1
    return valid, skipped


# ---------------------------------------------------------------------------
# MCP tool registration (FastMCP)
# ---------------------------------------------------------------------------

mcp = FastMCP("rag")


def _text(result):
    """Extract the text payload from a legacy handler result."""
    return result["content"][0]["text"]


@mcp.tool(name="rag_store_knowledge", description=(
    "Store a knowledge entry in the RAG knowledge base. Use this to save "
    "business rules, design decisions, architecture notes, or any insight "
    "that would be useful for future conversations about this project.\n\n"
    "Examples of when to use this:\n"
    "- The user explains a business rule: 'orders over 1000 require manager approval'\n"
    "- You discover a design pattern: 'the auth system uses JWT with refresh tokens'\n"
    "- The user makes a decision: 'we decided to use Postgres instead of MongoDB'\n"
    "- You learn about a constraint: 'the API rate limit is 100 req/min per user'\n\n"
    "IMPORTANT: Check the project language with rag_status first. "
    "Store title and content in the project's configured language.\n\n"
    "Format content as Markdown. Use headings (##), lists (-), code blocks (```), "
    "bold/italic, and other Markdown syntax to structure the note. The content is "
    "rendered as Markdown in the UI and search results.\n\n"
    "ALSO extract entities and relations to feed the knowledge graph: identify 2-8 "
    "salient entities (domain concepts, systems, roles, actors — not generic words) "
    "mentioned in the content, and the relations between them as subject-predicate-object "
    "triples (e.g. subject 'Order', predicate 'requires', object 'Manager Approval'). "
    "Write entity names and predicates in the project's configured language. Reuse "
    "entity names already seen in this project when they refer to the same thing, so "
    "the graph links up instead of fragmenting into near-duplicates.\n\n"
    "These entries go through an approval workflow before becoming searchable."
))
def store_knowledge(title: str, content: str, category: str = "insight",
                    tags: list[str] | None = None, project_id: str | None = None,
                    entities: list[EntityIn] | None = None,
                    relations: list[RelationIn] | None = None) -> str:
    args = {"title": title, "content": content, "category": category}
    if tags is not None:
        args["tags"] = tags
    if project_id is not None:
        args["project_id"] = project_id
    if entities is not None:
        args["entities"] = [e.model_dump() for e in entities]
    if relations is not None:
        args["relations"] = [r.model_dump() for r in relations]
    return _text(_store_knowledge(args))


@mcp.tool(name="rag_import_document", description=(
    "Import a markdown (.md) or text (.txt) file into the knowledge base. "
    "Markdown files are split by H1/H2 headers into separate entries. "
    "Supports YAML frontmatter for category and tags.\n\n"
    "Use this when the user has documentation, notes, or rules written in files."
))
def import_document(file_path: str, project_id: str | None = None,
                    category: str | None = None, tags: list[str] | None = None) -> str:
    args = {"file_path": file_path}
    if project_id is not None:
        args["project_id"] = project_id
    if category is not None:
        args["category"] = category
    if tags is not None:
        args["tags"] = tags
    return _text(_import_document(args))


@mcp.tool(name="rag_search", description=(
    "Search the knowledge base for relevant entries. Use this before answering "
    "questions about business rules, architecture, design decisions, or any "
    "project knowledge.\n\n"
    "Returns matching entries with relevance scores. Also expands results through "
    "the knowledge graph by default: entries connected to the vector-search hits via "
    "shared entities/relations are surfaced too, even when their wording doesn't "
    "overlap with the query."
))
def search(query: str, project_id: str | None = None, category: str | None = None,
           tags: list[str] | None = None, top_k: int = 5,
           expand_graph: bool = True, graph_depth: int = 1) -> str:
    args = {"query": query, "top_k": top_k, "expand_graph": expand_graph,
            "graph_depth": graph_depth}
    if project_id is not None:
        args["project_id"] = project_id
    if category is not None:
        args["category"] = category
    if tags is not None:
        args["tags"] = tags
    return _text(_search(args))


@mcp.tool(name="rag_list_knowledge", description=(
    "List knowledge entries in the knowledge base. Supports filtering by "
    "category, tags, and status. Use this to browse what's stored."
))
def list_knowledge(project_id: str | None = None, category: str | None = None,
                   tags: list[str] | None = None, status: str = "indexed") -> str:
    args = {"status": status}
    if project_id is not None:
        args["project_id"] = project_id
    if category is not None:
        args["category"] = category
    if tags is not None:
        args["tags"] = tags
    return _text(_list_knowledge(args))


@mcp.tool(name="rag_remove_knowledge",
          description="Remove a knowledge entry from the knowledge base by its ID or title.")
def remove_knowledge(entry_id: str | None = None, title: str | None = None,
                     project_id: str | None = None) -> str:
    args = {}
    if entry_id is not None:
        args["entry_id"] = entry_id
    if title is not None:
        args["title"] = title
    if project_id is not None:
        args["project_id"] = project_id
    return _text(_remove_knowledge(args))


@mcp.tool(name="rag_open_approval_ui", description=(
    "Open the admin panel to review and approve/reject pending knowledge entries. "
    "Call this after storing or importing knowledge so the user can review."
))
def open_approval_ui(port: int = 8000) -> str:
    return _text(_open_approval_ui({"port": port}))


@mcp.tool(name="rag_status",
          description="Show the status of the knowledge base for a project: entry counts, tags, categories.")
def status(project_id: str | None = None) -> str:
    args = {}
    if project_id is not None:
        args["project_id"] = project_id
    return _text(_status(args))


@mcp.tool(name="rag_list_projects", description="List all projects in the knowledge base.")
def list_projects() -> str:
    return _text(_list_projects({}))


@mcp.tool(name="rag_set_language", description=(
    "Set the language for a project. The AI should store all knowledge entries "
    "(title and content) in this language. Use this to configure the language "
    "for the knowledge base.\n\n"
    "Examples: 'pt-BR', 'en', 'es', 'fr'."
))
def set_language(language: str, project_id: str | None = None) -> str:
    args = {"language": language}
    if project_id is not None:
        args["project_id"] = project_id
    return _text(_set_language(args))


@mcp.tool(name="rag_query_graph", description=(
    "Query the knowledge graph for an entity: show what it's connected to and which "
    "indexed knowledge entries mention it. Use this to explore relationships between "
    "domain concepts, systems, or rules that vector search alone might not surface."
))
def query_graph(entity: str, depth: int = 1, project_id: str | None = None) -> str:
    args = {"entity": entity, "depth": depth}
    if project_id is not None:
        args["project_id"] = project_id
    return _text(_query_graph(args))


@mcp.tool(name="rag_add_project_path", description=(
    "Associate an additional filesystem path with an existing project. "
    "Useful for multi-repo projects (e.g., separate frontend and backend repos)."
))
def add_project_path(project_id: str, path: str) -> str:
    return _text(_add_project_path({"project_id": project_id, "path": path}))


# ---------------------------------------------------------------------------
# Tool handlers
# ---------------------------------------------------------------------------

def _store_knowledge(args):
    pid = _ensure_project(args)
    title = args["title"]
    content = args["content"]
    category = args.get("category", "insight")
    tags = args.get("tags", [])
    entities, skipped_entities = _valid_graph_items(args.get("entities"), ["name"])
    relations, skipped_relations = _valid_graph_items(
        args.get("relations"), ["subject", "predicate", "object"]
    )
    skipped = skipped_entities + skipped_relations

    try:
        entry_id = db.store_knowledge_entry(
            project_id=pid,
            title=title,
            content=content,
            category=category,
            source="assistant",
            tags=tags,
        )
    except db.sqlite3.IntegrityError:
        return {
            "content": [{
                "type": "text",
                "text": (f"An entry titled '{title}' already exists in project '{pid}'. "
                         f"Use a different title, or remove/update the existing entry."),
            }],
            "isError": True,
        }

    for entity in entities:
        entity_type = entity.get("type", "")
        if not isinstance(entity_type, str):
            entity_type = ""
        entity_id = db.upsert_entity(pid, entity["name"], entity_type)
        db.link_entry_entity(entry_id, entity_id)

    for relation in relations:
        db.add_relation(
            pid, relation["subject"], relation["predicate"], relation["object"],
            entry_id=entry_id,
        )

    project = db.get_project(pid)
    lang = project.get("language", "en") if project else "en"

    stats = db.get_project_stats(pid)
    graph_line = ""
    if entities or relations or skipped:
        skipped_str = f" ({skipped} malformed items skipped)" if skipped else ""
        graph_line = f"  Graph: {len(entities)} entities, {len(relations)} relations{skipped_str}\n"

    return {
        "content": [{
            "type": "text",
            "text": (
                f"Knowledge entry stored (pending approval).\n"
                f"  Title: {title}\n"
                f"  Category: {category}\n"
                f"  Tags: {', '.join(tags) if tags else '(none)'}\n"
                f"  Language: {lang}\n"
                f"{graph_line}"
                f"  ID: {entry_id}\n\n"
                f"Project: {pid} — {stats['pending']} pending, {stats['indexed']} indexed\n"
                f"Approve at http://127.0.0.1:8000/api"
            )
        }]
    }


def _import_document(args):
    pid = _ensure_project(args)
    filepath = args["file_path"]
    category = args.get("category", "insight")
    tags = args.get("tags")

    entry_ids = doc_import.import_document(db, pid, filepath, category, tags)

    return {
        "content": [{
            "type": "text",
            "text": (
                f"Imported {len(entry_ids)} entries from {filepath}.\n"
                f"  Project: {pid}\n"
                f"  Status: pending (needs approval)\n\n"
                f"Approve at http://127.0.0.1:8000/api"
            )
        }]
    }


def _search(args):
    pid = _resolve_project_id(args)
    query = args["query"]
    category = args.get("category")
    tags = args.get("tags")
    top_k = args.get("top_k", 5)
    expand_graph = args.get("expand_graph", True)
    graph_depth = _coerce_depth(args.get("graph_depth", 1))

    project = db.get_project(pid)
    if not project:
        return {"content": [{"type": "text", "text": f"Project '{pid}' not found. Store knowledge first."}]}

    entries = db.get_indexed_entries(pid)
    if not entries:
        return {"content": [{"type": "text", "text": f"No indexed knowledge in '{project['name']}'. Use rag_store_knowledge and approve entries first."}]}

    query_vec = embeddings.embed_query(query)
    results = db.hybrid_search(
        query, query_vec,
        project_id=pid, k=top_k, category=category, tags=tags,
        min_score=api.search_min_score(),
    )

    if not results:
        return {"content": [{"type": "text", "text": f"No results for '{query}' in '{project['name']}'."}]}

    lines = [f"Search: '{query}' in '{project['name']}' ({len(entries)} entries)\n"]
    for i, r in enumerate(results, 1):
        tags_str = f" [{', '.join(r['tags'])}]" if r.get("tags") else ""
        preview = r["content"][:300].replace("\n", " ")
        if len(r["content"]) > 300:
            preview += "..."
        lines.append(f"  [{i}] {r['title']} ({r['category']}){tags_str} (score: {r['score']})")
        lines.append(f"      {preview}\n")

    if expand_graph:
        seed_ids = [r["id"] for r in results]
        expansion = db.expand_entries_via_graph(pid, seed_ids, depth=graph_depth, limit=5)
        triples = expansion.get("triples") or []
        related_entries = expansion.get("related_entries") or []
        if triples or related_entries:
            lines.append("Related via knowledge graph:\n")
            for t in triples:
                lines.append(f"  {t['subject']} —{t['predicate']}→ {t['object']}")
            if triples and related_entries:
                lines.append("")
            for e in related_entries:
                tags_str = f" [{', '.join(e['tags'])}]" if e.get("tags") else ""
                preview = e["content"][:200].replace("\n", " ")
                if len(e["content"]) > 200:
                    preview += "..."
                lines.append(f"  {e['title']} ({e['category']}){tags_str}")
                lines.append(f"    {preview}\n")

    return {"content": [{"type": "text", "text": "\n".join(lines)}]}


def _list_knowledge(args):
    pid = _resolve_project_id(args)
    category = args.get("category")
    tags = args.get("tags")
    status = args.get("status", "indexed")

    project = db.get_project(pid)
    if not project:
        return {"content": [{"type": "text", "text": f"Project '{pid}' not found."}]}

    entries = db.list_entries(pid, category=category, tags=tags, status=status)

    if not entries:
        return {"content": [{"type": "text", "text": f"No entries found in '{project['name']}'."}]}

    lines = [f"Knowledge in '{project['name']}' ({status}):\n"]
    for e in entries:
        tags_str = f" [{', '.join(e['tags'])}]" if e.get("tags") else ""
        lines.append(f"  {e['title']} ({e['category']}){tags_str}")
        lines.append(f"    ID: {e['id']}\n")

    return {"content": [{"type": "text", "text": "\n".join(lines)}]}


def _remove_knowledge(args):
    pid = _resolve_project_id(args)
    entry_id = args.get("entry_id")
    title = args.get("title")

    if entry_id:
        db.remove_entry(entry_id)
        return {"content": [{"type": "text", "text": f"Removed entry {entry_id}."}]}
    elif title:
        entries = db.list_entries(pid, status=None)
        match = [e for e in entries if e["title"].lower() == title.lower()]
        if match:
            db.remove_entry(match[0]["id"])
            return {"content": [{"type": "text", "text": f"Removed '{title}'."}]}
        return {"content": [{"type": "text", "text": f"No entry titled '{title}' found."}]}
    else:
        return {"content": [{"type": "text", "text": "Provide entry_id or title."}], "isError": True}


def _open_approval_ui(args):
    port = args.get("port", 8000)
    url = f"http://127.0.0.1:{port}"

    try:
        import webbrowser
        webbrowser.open(url)
    except Exception:
        pass

    return {"content": [{"type": "text", "text": f"Admin panel at {url}"}]}


def _status(args):
    pid = _resolve_project_id(args)
    project = db.get_project(pid)
    if not project:
        return {"content": [{"type": "text", "text": f"Project '{pid}' not found."}]}

    stats = db.get_project_stats(pid)
    tags = db.get_all_tags(pid)

    # Count by category
    entries = db.get_indexed_entries(pid)
    cat_counts = {}
    for e in entries:
        cat_counts[e["category"]] = cat_counts.get(e["category"], 0) + 1

    paths = project.get("paths") or []
    root_display = paths[0] if paths else project.get("root_path", "(none)")
    lines = [
        f"Project: {project['name']} ({project['id']})",
        f"  Root: {root_display}",
        f"  Description: {project.get('description') or '(none)'}",
        f"  Language: {project.get('language', 'en')}\n",
        f"  Total: {stats['total']} | Indexed: {stats['indexed']} | Pending: {stats['pending']} | Rejected: {stats['rejected']}",
    ]

    if cat_counts:
        lines.append("\n  By category:")
        for cat, count in sorted(cat_counts.items()):
            lines.append(f"    {cat}: {count}")

    if tags:
        lines.append(f"\n  Tags: {', '.join(tags)}")

    return {"content": [{"type": "text", "text": "\n".join(lines)}]}


def _list_projects(args):
    projects = db.list_projects()
    if not projects:
        return {"content": [{"type": "text", "text": "No projects registered. Store knowledge to create one."}]}

    lines = ["Projects in Knowledge Base:\n"]
    for p in projects:
        lines.append(f"  {p['name']} ({p['id']})")
        for path in p.get("paths") or [p.get("root_path")] or []:
            if path:
                lines.append(f"    Path: {path}")
        lines.append(f"    Language: {p.get('language', 'en')}")
        lines.append(f"    Indexed: {p['indexed_count']} | Pending: {p['pending_count']}\n")

    return {"content": [{"type": "text", "text": "\n".join(lines)}]}


def _set_language(args):
    pid = _resolve_project_id(args)
    language = args["language"]

    project = db.get_project(pid)
    if project:
        db.upsert_project(
            pid, project["name"], project["root_path"],
            project.get("description", ""), project.get("project_type", ""),
            language=language,
        )
    else:
        root_path = args.get("root_path", os.getcwd())
        name = args.get("project_name", os.path.basename(os.path.abspath(root_path)))
        db.upsert_project(pid, name, root_path, "", "", language=language)

    return {
        "content": [{
            "type": "text",
            "text": (
                f"Language set to '{language}' for project '{pid}'.\n"
                f"All knowledge entries should be stored in this language."
            )
        }]
    }


def _query_graph(args):
    pid = _resolve_project_id(args)
    entity_name = args["entity"]
    depth = _coerce_depth(args.get("depth", 1))

    project = db.get_project(pid)
    if not project:
        return {"content": [{"type": "text", "text": f"Project '{pid}' not found."}]}

    result = db.query_entity_graph(pid, entity_name, depth=depth)

    if not result.get("entity"):
        graph = db.get_graph(pid)
        top = sorted(graph["entities"], key=lambda e: e["entry_count"], reverse=True)[:10]
        if top:
            names = ", ".join(e["name"] for e in top)
            text = (
                f"No entity named '{entity_name}' in '{project['name']}'.\n"
                f"Known entities: {names}"
            )
        else:
            text = (
                f"No entity named '{entity_name}' in '{project['name']}'.\n"
                f"This project's knowledge graph is empty — store entries with entities/relations first."
            )
        return {"content": [{"type": "text", "text": text}]}

    entity = result["entity"]
    triples = result.get("triples") or []
    entries = result.get("entries") or []

    lines = [f"Entity: {entity['name']}" + (f" ({entity['type']})" if entity.get("type") else "")]
    lines.append(f"Project: {project['name']}\n")

    if triples:
        by_predicate = {}
        for t in triples:
            by_predicate.setdefault(t["predicate"], []).append(t)
        lines.append("Relations:")
        for predicate, group in by_predicate.items():
            lines.append(f"  {predicate}:")
            for t in group:
                lines.append(f"    {t['subject']} —{t['predicate']}→ {t['object']}")
        lines.append("")
    else:
        lines.append("No relations found for this entity.\n")

    if entries:
        lines.append("Entries mentioning this entity:")
        for e in entries:
            tags_str = f" [{', '.join(e['tags'])}]" if e.get("tags") else ""
            lines.append(f"  {e['title']} ({e['category']}){tags_str}")
            lines.append(f"    ID: {e['id']}")
    else:
        lines.append("No indexed entries mention this entity.")

    return {"content": [{"type": "text", "text": "\n".join(lines)}]}


def _add_project_path(args):
    pid = args["project_id"]
    path = args["path"]

    project = db.get_project(pid)
    if not project:
        return {"content": [{"type": "text", "text": f"Project '{pid}' not found."}]}

    db.add_project_path(pid, path)
    paths = db.list_project_paths(pid)
    paths_str = "\n  ".join(paths)

    return {"content": [{"type": "text", "text": f"Path added to project '{project['name']}'.\n  {paths_str}"}]}


# ---------------------------------------------------------------------------
# Main MCP server loop
# ---------------------------------------------------------------------------

def main():
    db.init_db()
    import indexing
    indexing.ensure_index_current(log=log)
    log("Knowledge Base RAG MCP server starting...")
    try:
        api.start_api_server(8000)
        log("Admin panel API at http://127.0.0.1:8000")
        log("Admin panel UI at http://127.0.0.1:8765")
    except Exception as e:
        log(f"Could not start API server: {e}")
    mcp.run()  # stdio transport by default


if __name__ == "__main__":
    main()
