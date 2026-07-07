#!/usr/bin/env python3
"""MCP server for Knowledge Base RAG plugin.

Implements the Model Context Protocol (JSON-RPC 2.0 over stdio) for Codex.
Provides tools for storing and searching knowledge entries with approval workflow.
"""

import json
import os
import sys
import traceback
import re

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import db
import embeddings
import doc_import
import api

MCP_VERSION = "2024-11-05"
SERVER_NAME = "rag"
SERVER_VERSION = "0.3.0"


def log(msg):
    sys.stderr.write(f"[rag] {msg}\n")
    sys.stderr.flush()


# ---------------------------------------------------------------------------
# MCP Protocol helpers
# ---------------------------------------------------------------------------

def send_message(msg):
    sys.stdout.write(json.dumps(msg) + "\n")
    sys.stdout.flush()


def read_message():
    line = sys.stdin.readline()
    if not line:
        return None
    line = line.strip()
    if not line:
        return None
    try:
        return json.loads(line)
    except json.JSONDecodeError:
        return None


def send_result(msg_id, result):
    send_message({"jsonrpc": "2.0", "id": msg_id, "result": result})


def send_error(msg_id, code, message):
    send_message({"jsonrpc": "2.0", "id": msg_id, "error": {"code": code, "message": message}})


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
# Tool definitions
# ---------------------------------------------------------------------------

TOOLS = [
    {
        "name": "rag_store_knowledge",
        "description": (
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
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "title": {
                    "type": "string",
                    "description": "Short title for the knowledge entry."
                },
                "content": {
                    "type": "string",
                    "description": "The full content/body of the knowledge entry, formatted as Markdown (use ## headings, - lists, ``` code blocks, **bold**, etc.)."
                },
                "category": {
                    "type": "string",
                    "description": "Category: business-rule, design-decision, architecture, documentation, insight, convention, constraint",
                    "default": "insight"
                },
                "tags": {
                    "type": "array",
                    "items": {"type": "string"},
                    "description": "Optional tags for organization."
                },
                "project_id": {
                    "type": "string",
                    "description": "Project ID. If omitted, auto-detected from current directory."
                },
                "entities": {
                    "type": "array",
                    "items": {
                        "type": "object",
                        "properties": {
                            "name": {"type": "string"},
                            "type": {"type": "string"}
                        },
                        "required": ["name"]
                    },
                    "description": "Salient entities mentioned in the content, for the knowledge graph (2-8 typical). Each has a name and an optional free-text type (e.g. 'system', 'role', 'concept')."
                },
                "relations": {
                    "type": "array",
                    "items": {
                        "type": "object",
                        "properties": {
                            "subject": {"type": "string"},
                            "predicate": {"type": "string"},
                            "object": {"type": "string"}
                        },
                        "required": ["subject", "predicate", "object"]
                    },
                    "description": "Subject-predicate-object triples linking the entities above, for the knowledge graph."
                }
            },
            "required": ["title", "content"]
        }
    },
    {
        "name": "rag_import_document",
        "description": (
            "Import a markdown (.md) or text (.txt) file into the knowledge base. "
            "Markdown files are split by H1/H2 headers into separate entries. "
            "Supports YAML frontmatter for category and tags.\n\n"
            "Use this when the user has documentation, notes, or rules written in files."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "file_path": {
                    "type": "string",
                    "description": "Path to the .md or .txt file to import."
                },
                "project_id": {
                    "type": "string",
                    "description": "Project ID. If omitted, auto-detected from current directory."
                },
                "category": {
                    "type": "string",
                    "description": "Default category for imported entries (overridden by frontmatter)."
                },
                "tags": {
                    "type": "array",
                    "items": {"type": "string"},
                    "description": "Default tags for imported entries (overridden by frontmatter)."
                }
            },
            "required": ["file_path"]
        }
    },
    {
        "name": "rag_search",
        "description": (
            "Search the knowledge base for relevant entries. Use this before answering "
            "questions about business rules, architecture, design decisions, or any "
            "project knowledge.\n\n"
            "Returns matching entries with relevance scores. Also expands results through "
            "the knowledge graph by default: entries connected to the vector-search hits via "
            "shared entities/relations are surfaced too, even when their wording doesn't "
            "overlap with the query."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "query": {
                    "type": "string",
                    "description": "Search query."
                },
                "project_id": {
                    "type": "string",
                    "description": "Project ID. If omitted, auto-detected from current directory."
                },
                "category": {
                    "type": "string",
                    "description": "Filter results by category."
                },
                "tags": {
                    "type": "array",
                    "items": {"type": "string"},
                    "description": "Filter results by tags (entry must have ALL specified tags)."
                },
                "top_k": {
                    "type": "number",
                    "description": "Maximum results to return (default: 5).",
                    "default": 5
                },
                "expand_graph": {
                    "type": "boolean",
                    "description": "Expand results via the knowledge graph (related entities/entries). Default true.",
                    "default": True
                },
                "graph_depth": {
                    "type": "number",
                    "description": "Number of hops to traverse in the knowledge graph when expanding (max 2). Default 1.",
                    "default": 1
                }
            },
            "required": ["query"]
        }
    },
    {
        "name": "rag_list_knowledge",
        "description": (
            "List knowledge entries in the knowledge base. Supports filtering by "
            "category, tags, and status. Use this to browse what's stored."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "project_id": {
                    "type": "string",
                    "description": "Project ID. If omitted, auto-detected from current directory."
                },
                "category": {
                    "type": "string",
                    "description": "Filter by category."
                },
                "tags": {
                    "type": "array",
                    "items": {"type": "string"},
                    "description": "Filter by tags."
                },
                "status": {
                    "type": "string",
                    "description": "Filter by status: pending, indexed, rejected. Default: indexed."
                }
            }
        }
    },
    {
        "name": "rag_remove_knowledge",
        "description": "Remove a knowledge entry from the knowledge base by its ID or title.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "entry_id": {
                    "type": "string",
                    "description": "Entry ID to remove."
                },
                "title": {
                    "type": "string",
                    "description": "Entry title to remove (alternative to entry_id)."
                },
                "project_id": {
                    "type": "string",
                    "description": "Project ID (required when using title)."
                }
            }
        }
    },
    {
        "name": "rag_open_approval_ui",
        "description": (
            "Open the admin panel to review and approve/reject pending knowledge entries. "
            "Call this after storing or importing knowledge so the user can review."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "port": {
                    "type": "number",
                    "description": "Port for the admin panel (default: 8000).",
                    "default": 8000
                }
            }
        }
    },
    {
        "name": "rag_status",
        "description": "Show the status of the knowledge base for a project: entry counts, tags, categories.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "project_id": {
                    "type": "string",
                    "description": "Project ID. If omitted, auto-detected from current directory."
                }
            }
        }
    },
    {
        "name": "rag_list_projects",
        "description": "List all projects in the knowledge base.",
        "inputSchema": {
            "type": "object",
            "properties": {}
        }
    },
    {
        "name": "rag_set_language",
        "description": (
            "Set the language for a project. The AI should store all knowledge entries "
            "(title and content) in this language. Use this to configure the language "
            "for the knowledge base.\n\n"
            "Examples: 'pt-BR', 'en', 'es', 'fr'."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "language": {
                    "type": "string",
                    "description": "Language code (e.g. 'pt-BR', 'en', 'es')."
                },
                "project_id": {
                    "type": "string",
                    "description": "Project ID. If omitted, auto-detected from current directory."
                }
            },
            "required": ["language"]
        }
    },
    {
        "name": "rag_query_graph",
        "description": (
            "Query the knowledge graph for an entity: show what it's connected to and which "
            "indexed knowledge entries mention it. Use this to explore relationships between "
            "domain concepts, systems, or rules that vector search alone might not surface."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "entity": {
                    "type": "string",
                    "description": "Entity name to look up (e.g. 'Order', 'Manager Approval')."
                },
                "depth": {
                    "type": "number",
                    "description": "Number of hops to traverse from the entity (max 2). Default 1.",
                    "default": 1
                },
                "project_id": {
                    "type": "string",
                    "description": "Project ID. If omitted, auto-detected from current directory."
                }
            },
            "required": ["entity"]
        }
    },
    {
        "name": "rag_add_project_path",
        "description": (
            "Associate an additional filesystem path with an existing project. "
            "Useful for multi-repo projects (e.g., separate frontend and backend repos)."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "project_id": {
                    "type": "string",
                    "description": "Project ID",
                },
                "path": {
                    "type": "string",
                    "description": "Filesystem path to associate",
                },
            },
            "required": ["project_id", "path"],
        },
    }
]


# ---------------------------------------------------------------------------
# Tool handlers
# ---------------------------------------------------------------------------

def handle_tool_call(name, args):
    try:
        if name == "rag_store_knowledge":
            return _store_knowledge(args)
        elif name == "rag_import_document":
            return _import_document(args)
        elif name == "rag_search":
            return _search(args)
        elif name == "rag_list_knowledge":
            return _list_knowledge(args)
        elif name == "rag_remove_knowledge":
            return _remove_knowledge(args)
        elif name == "rag_open_approval_ui":
            return _open_approval_ui(args)
        elif name == "rag_status":
            return _status(args)
        elif name == "rag_list_projects":
            return _list_projects(args)
        elif name == "rag_set_language":
            return _set_language(args)
        elif name == "rag_query_graph":
            return _query_graph(args)
        elif name == "rag_add_project_path":
            return _add_project_path(args)
        else:
            return {"content": [{"type": "text", "text": f"Unknown tool: {name}"}], "isError": True}
    except Exception as e:
        log(f"Error in {name}: {traceback.format_exc()}")
        return {"content": [{"type": "text", "text": f"Error: {e}"}], "isError": True}


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

    entry_id = db.store_knowledge_entry(
        project_id=pid,
        title=title,
        content=content,
        category=category,
        source="assistant",
        tags=tags,
    )

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
    results = db.search_entries_by_embedding(
        query_vec, project_id=pid, k=top_k, category=category, tags=tags,
    )

    results = [r for r in results if r.get("score", 0) >= api.search_min_score()]

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
        seed_ids = [r["entry_id"] for r in results]
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
    log("Knowledge Base RAG MCP server starting...")

    try:
        api.start_api_server(8000)
        log("Admin panel API at http://127.0.0.1:8000")
        log("Admin panel UI at http://127.0.0.1:8765")
    except Exception as e:
        log(f"Could not start API server: {e}")

    while True:
        msg = read_message()
        if msg is None:
            break

        method = msg.get("method")
        msg_id = msg.get("id")
        params = msg.get("params", {})

        if method == "initialize":
            send_result(msg_id, {
                "protocolVersion": MCP_VERSION,
                "capabilities": {"tools": {}},
                "serverInfo": {"name": SERVER_NAME, "version": SERVER_VERSION},
            })
            continue

        if method == "notifications/initialized":
            continue

        if method == "tools/list":
            tools_info = [{
                "name": t["name"],
                "description": t["description"],
                "inputSchema": t["inputSchema"],
            } for t in TOOLS]
            send_result(msg_id, {"tools": tools_info})
            continue

        if method == "tools/call":
            tool_name = params.get("name", "")
            tool_args = params.get("arguments", {})
            result = handle_tool_call(tool_name, tool_args)
            send_result(msg_id, result)
            continue

        if method == "ping":
            send_result(msg_id, {})
            continue

        send_error(msg_id, -32601, f"Method not found: {method}")


if __name__ == "__main__":
    main()
