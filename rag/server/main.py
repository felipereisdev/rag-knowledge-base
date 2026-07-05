#!/usr/bin/env python3
"""MCP server for RAG Knowledge Base plugin.

Implements the Model Context Protocol (JSON-RPC 2.0 over stdio) for Codex.
Provides tools for per-project RAG indexing with approval workflow.
"""

import json
import os
import sys
import traceback
import hashlib
import re

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import db
import rag_engine
import web_ui

MCP_VERSION = "2024-11-05"
SERVER_NAME = "rag"
SERVER_VERSION = "0.2.0"


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
    """Convert a string to a safe project ID slug."""
    text = text.lower().strip()
    text = re.sub(r"[^a-z0-9]+", "-", text)
    text = text.strip("-")
    return text or "project"


def _project_id_from_path(root_path):
    """Generate a project_id from a directory path."""
    base = os.path.basename(os.path.abspath(root_path))
    return _slugify(base)


# ---------------------------------------------------------------------------
# Tool definitions
# ---------------------------------------------------------------------------
TOOLS = [
    {
        "name": "rag_auto_init",
        "description": (
            "Automatically detect and initialize the current project for RAG. "
            "Call this when you start working on a new project or when the user asks "
            "to index/search a project. Detects the project type (Django, FastAPI, "
            "Express, Next.js, Go, Rust, etc.) and sets up smart defaults.\n\n"
            "You should call this proactively when you encounter a project you haven't "
            "indexed yet. The user does not need to configure anything."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "root_path": {
                    "type": "string",
                    "description": "Project root path. If omitted, uses the current working directory."
                },
                "description": {
                    "type": "string",
                    "description": "Optional description of what the project does. If omitted, auto-detected from project type."
                }
            }
        }
    },
    {
        "name": "rag_auto_scan",
        "description": (
            "Automatically scan the project using smart defaults based on the detected "
            "project type. Uses include/exclude patterns appropriate for the framework "
            "(e.g. skips migrations in Django, skips node_modules in Node.js).\n\n"
            "Call this after rag_auto_init to index the project. The model should then "
            "call rag_open_approval_ui so the user can review what will be indexed."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "project_id": {
                    "type": "string",
                    "description": "Project ID. If omitted, auto-detected from the current directory."
                },
                "max_files": {
                    "type": "number",
                    "description": "Maximum files to process (default: 200)"
                }
            }
        }
    },
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
            "These entries go through the same approval workflow as file chunks."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "project_id": {
                    "type": "string",
                    "description": "Project ID. If omitted, auto-detected from the current directory."
                },
                "title": {
                    "type": "string",
                    "description": "Short title for the knowledge entry (e.g. 'Order approval rule')"
                },
                "content": {
                    "type": "string",
                    "description": "The knowledge content. Be detailed and specific. Include context, rationale, and implications."
                },
                "category": {
                    "type": "string",
                    "description": "Category: 'business-rule', 'design-decision', 'architecture', 'constraint', 'convention', or 'insight'",
                    "default": "insight"
                }
            },
            "required": ["title", "content"]
        }
    },
    {
        "name": "rag_scan_files",
        "description": (
            "Manually scan project files with custom filters. For automatic smart scanning, "
            "use rag_auto_scan instead. Use this when you need fine-grained control over "
            "what gets indexed."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "project_id": {"type": "string", "description": "Project ID"},
                "path": {"type": "string", "description": "Path to scan (defaults to project root)"},
                "extensions": {
                    "type": "array",
                    "items": {"type": "string"},
                    "description": "File extensions to include"
                },
                "max_files": {"type": "number", "description": "Maximum files (default: 200)"},
                "include_patterns": {
                    "type": "array",
                    "items": {"type": "string"},
                    "description": "Glob patterns to INCLUDE"
                },
                "exclude_patterns": {
                    "type": "array",
                    "items": {"type": "string"},
                    "description": "Glob patterns to EXCLUDE"
                },
                "skip_generated": {"type": "boolean", "description": "Skip generated files (default: true)"},
                "skip_tests": {"type": "boolean", "description": "Skip test files (default: false)"},
                "relevance_hint": {"type": "string", "description": "What content is relevant"}
            },
            "required": ["project_id"]
        }
    },
    {
        "name": "rag_show_pending",
        "description": "Show pending chunks awaiting approval for a project.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "project_id": {"type": "string", "description": "Project ID (optional)"}
            }
        }
    },
    {
        "name": "rag_approve",
        "description": "Approve pending chunks by ID, or '__ALL__' to approve everything pending.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "chunk_ids": {
                    "type": "array",
                    "items": {"type": "string"},
                    "description": "Chunk IDs or ['__ALL__']"
                }
            },
            "required": ["chunk_ids"]
        }
    },
    {
        "name": "rag_reject",
        "description": "Reject pending chunks by ID, or '__ALL__' to reject everything pending.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "chunk_ids": {
                    "type": "array",
                    "items": {"type": "string"},
                    "description": "Chunk IDs or ['__ALL__']"
                }
            },
            "required": ["chunk_ids"]
        }
    },
    {
        "name": "rag_search",
        "description": (
            "Search the indexed knowledge base for a project using semantic TF-IDF search. "
            "Call this proactively when the user asks about the codebase, business logic, "
            "or design decisions."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "project_id": {"type": "string", "description": "Project ID. If omitted, auto-detected from CWD."},
                "query": {"type": "string", "description": "Search query"},
                "top_k": {"type": "number", "description": "Results (default: 5)"}
            },
            "required": ["query"]
        }
    },
    {
        "name": "rag_list_projects",
        "description": "List all projects tracked in the knowledge base with indexing stats.",
        "inputSchema": {"type": "object", "properties": {}}
    },
    {
        "name": "rag_status",
        "description": "Show indexing status and stats for a project.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "project_id": {"type": "string", "description": "Project ID. If omitted, auto-detected from CWD."}
            }
        }
    },
    {
        "name": "rag_remove_document",
        "description": "Remove a document and its chunks from the knowledge base.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "project_id": {"type": "string", "description": "Project ID"},
                "rel_path": {"type": "string", "description": "Relative file path"}
            },
            "required": ["project_id", "rel_path"]
        }
    },
    {
        "name": "rag_open_approval_ui",
        "description": "Open the approval web UI in the browser to review and approve/reject pending chunks.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "port": {"type": "number", "description": "HTTP port (default: 8765)"}
            }
        }
    },
    {
        "name": "rag_search_all",
        "description": "Search across ALL indexed projects.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "query": {"type": "string", "description": "Search query"},
                "top_k_per_project": {"type": "number", "description": "Results per project (default: 3)"}
            },
            "required": ["query"]
        }
    },
]


# ---------------------------------------------------------------------------
# Tool handlers
# ---------------------------------------------------------------------------

def handle_tool_call(name, arguments):
    handler_map = {
        "rag_auto_init": _auto_init,
        "rag_auto_scan": _auto_scan,
        "rag_store_knowledge": _store_knowledge,
        "rag_scan_files": _scan_files,
        "rag_show_pending": _show_pending,
        "rag_approve": _approve,
        "rag_reject": _reject,
        "rag_search": _search,
        "rag_list_projects": _list_projects,
        "rag_status": _status,
        "rag_remove_document": _remove_document,
        "rag_open_approval_ui": _open_approval_ui,
        "rag_search_all": _search_all,
    }
    handler = handler_map.get(name)
    if not handler:
        return {"content": [{"type": "text", "text": f"Unknown tool: {name}"}], "isError": True}
    try:
        result = handler(arguments)
        return {"content": [{"type": "text", "text": result}]}
    except Exception as e:
        log(f"Error in {name}: {traceback.format_exc()}")
        return {"content": [{"type": "text", "text": f"Error: {str(e)}"}], "isError": True}


def _resolve_project_id(args, key="project_id"):
    """Resolve project_id from args or auto-detect from CWD."""
    pid = args.get(key)
    if pid:
        return pid
    cwd = os.getcwd()
    return _project_id_from_path(cwd)


def _auto_init(args):
    root_path = args.get("root_path") or os.getcwd()
    root_path = os.path.abspath(root_path)

    if not os.path.isdir(root_path):
        return f"Error: '{root_path}' is not a valid directory."

    project_id = _project_id_from_path(root_path)
    name = os.path.basename(root_path).replace("-", " ").replace("_", " ").title()

    # Detect project type
    project_type, type_desc = rag_engine.detect_project_type(root_path)

    # Use provided description or auto-generated
    description = args.get("description") or type_desc

    # Check if already exists
    existing = db.get_project(project_id)
    db.upsert_project(project_id, name, root_path, description, project_type or "")

    if existing:
        return (
            f"Project '{name}' ({project_id}) already initialized.\n"
            f"  Type: {project_type or 'unknown'} ({description})\n"
            f"  Root: {root_path}\n"
            f"  Use rag_auto_scan to index files, or rag_search to query."
        )

    return (
        f"Project '{name}' ({project_id}) initialized.\n"
        f"  Type: {project_type or 'unknown'} ({description})\n"
        f"  Root: {root_path}\n"
        f"\n"
        f"Next steps:\n"
        f"  rag_auto_scan  - Index files with smart defaults\n"
        f"  rag_search     - Query the knowledge base"
    )


def _auto_scan(args):
    project_id = _resolve_project_id(args)
    project = db.get_project(project_id)

    if not project:
        # Auto-init if not found
        cwd = os.getcwd()
        root_path = args.get("root_path") or cwd
        result = _auto_init({"root_path": root_path})
        project = db.get_project(project_id)
        if not project:
            return f"Error: Could not initialize project '{project_id}'."

    root_path = project["root_path"]
    project_type = project.get("project_type", "")

    # Get smart patterns for this project type
    strategy = rag_engine.get_scan_strategy(project_type)
    include_patterns = strategy.get("include")
    exclude_patterns = strategy.get("exclude", [])
    skip_tests = strategy.get("skip_tests", True)

    max_files = args.get("max_files", 200)

    files = rag_engine.scan_project(
        root_path,
        max_files=max_files,
        include_patterns=include_patterns,
        exclude_patterns=exclude_patterns,
        skip_generated=True,
        skip_tests=skip_tests,
    )

    if not files:
        return (
            f"No files found in '{root_path}'.\n"
            f"  Project type: {project_type or 'unknown'}\n"
            f"  Try rag_scan_files with custom patterns."
        )

    # Classify and report
    type_counts = {}
    for f in files:
        ft = f.get("type", "source")
        type_counts[ft] = type_counts.get(ft, 0) + 1

    # Check for changed/new files
    new_files = []
    unchanged = 0
    for f in files:
        file_hash = db.compute_file_hash(f["full_path"])
        existing = db.get_document(project_id, f["rel_path"])
        if existing and existing["file_hash"] == file_hash:
            unchanged += 1
            continue
        new_files.append(f)

    if not new_files:
        type_summary = " | ".join(f"{k}: {v}" for k, v in sorted(type_counts.items()))
        return (
            f"All files already indexed and unchanged.\n"
            f"  Project: {project['name']} ({project_type or 'unknown'})\n"
            f"  Files: {len(files)} | Types: {type_summary}\n"
            f"  Use rag_search to query the knowledge base."
        )

    # Read and chunk
    total_chunks = 0
    file_details = []
    for f in new_files:
        content = rag_engine.read_file_content(f["full_path"])
        if not content:
            continue
        chunks = rag_engine.chunk_text(content)
        if not chunks:
            continue

        file_type = f.get("type", "source")
        doc_id = db.upsert_document(project_id, f["rel_path"], db.compute_file_hash(f["full_path"]), file_type)
        n = db.insert_chunks(project_id, doc_id, chunks, file_type)
        total_chunks += n
        file_details.append(f"    {f['type']:>8}  {f['rel_path']} ({n} chunks)")

    type_summary = " | ".join(f"{k}: {v}" for k, v in sorted(type_counts.items()))

    lines = [
        f"Auto-scanned '{project['name']}' ({project_type or 'unknown'}):",
        f"  Root: {root_path}",
        f"  Files found: {len(files)} | Types: {type_summary}",
        f"  New/changed: {len(new_files)} | Unchanged: {unchanged}",
        f"  Total chunks: {total_chunks} (pending approval)",
        "",
        "Files:"
    ]
    lines.extend(file_details)
    lines.append("")
    lines.append("Next steps:")
    lines.append("  rag_open_approval_ui  - Review and approve in browser (recommended)")
    lines.append("  rag_approve({chunk_ids: ['__ALL__']})  - Approve all")
    lines.append("  rag_search  - Search after approving")
    return "\n".join(lines)


def _store_knowledge(args):
    project_id = _resolve_project_id(args)
    project = db.get_project(project_id)

    if not project:
        # Auto-init
        cwd = os.getcwd()
        _auto_init({"root_path": cwd})
        project = db.get_project(project_id)
        if not project:
            return f"Error: Could not initialize project '{project_id}'."

    title = args["title"]
    content = args["content"]
    category = args.get("category", "insight")

    # Build a rich knowledge entry
    full_content = f"[{category.upper()}] {title}\n\n{content}"

    chunk_id = db.insert_knowledge_chunk(project_id, title, full_content, source=category)

    return (
        f"Knowledge entry stored for '{project['name']}'.\n"
        f"  Title: {title}\n"
        f"  Category: {category}\n"
        f"  Chunk ID: {chunk_id}\n"
        f"  Status: pending (needs approval)\n"
        f"\n"
        f"Use rag_open_approval_ui or rag_approve to make it searchable."
    )


def _scan_files(args):
    project_id = args["project_id"]
    project = db.get_project(project_id)
    if not project:
        return f"Error: Project '{project_id}' not found. Use rag_auto_init first."

    path = args.get("path", project["root_path"])
    path = os.path.abspath(path)
    if not os.path.isdir(path):
        return f"Error: Path '{path}' is not a valid directory."

    extensions = args.get("extensions", None)
    max_files = args.get("max_files", 200)
    include_patterns = args.get("include_patterns", None)
    exclude_patterns = args.get("exclude_patterns", None)
    skip_generated = args.get("skip_generated", True)
    skip_tests = args.get("skip_tests", False)
    relevance_hint = args.get("relevance_hint", None)

    hint_note = ""
    if relevance_hint and relevance_hint.strip():
        hint_note = f"\n  Relevance filter: '{relevance_hint}'"

    files = rag_engine.scan_project(
        path,
        extensions=extensions,
        max_files=max_files,
        include_patterns=include_patterns,
        exclude_patterns=exclude_patterns,
        skip_generated=skip_generated,
        skip_tests=skip_tests,
    )

    if not files:
        return f"No supported files found in '{path}'."

    type_counts = {}
    for f in files:
        ft = f.get("type", "source")
        type_counts[ft] = type_counts.get(ft, 0) + 1

    new_files = []
    unchanged = 0
    for f in files:
        file_hash = db.compute_file_hash(f["full_path"])
        existing = db.get_document(project_id, f["rel_path"])
        if existing and existing["file_hash"] == file_hash:
            unchanged += 1
            continue
        new_files.append(f)

    if not new_files:
        type_summary = " | ".join(f"{k}: {v}" for k, v in sorted(type_counts.items()))
        return f"All files already indexed and unchanged.\n  Files: {len(files)} | Types: {type_summary}"

    total_chunks = 0
    file_details = []
    for f in new_files:
        content = rag_engine.read_file_content(f["full_path"])
        if not content:
            continue
        chunks = rag_engine.chunk_text(content)
        if not chunks:
            continue

        file_type = f.get("type", "source")
        doc_id = db.upsert_document(project_id, f["rel_path"], db.compute_file_hash(f["full_path"]), file_type)
        n = db.insert_chunks(project_id, doc_id, chunks, file_type)
        total_chunks += n
        file_details.append(f"    {f['type']:>8}  {f['rel_path']} ({n} chunks)")

    type_summary = " | ".join(f"{k}: {v}" for k, v in sorted(type_counts.items()))

    lines = [
        f"Scanned '{path}':{hint_note}",
        f"  Files: {len(files)} | Types: {type_summary}",
        f"  New/changed: {len(new_files)} | Unchanged: {unchanged}",
        f"  Total chunks: {total_chunks} (pending)",
        "",
        "Files:"
    ]
    lines.extend(file_details)
    lines.append("")
    lines.append("  rag_open_approval_ui  - Review in browser")
    return "\n".join(lines)


def _show_pending(args):
    pid = args.get("project_id")
    if pid is None:
        pid = _resolve_project_id(args)

    chunks = db.get_pending_chunks(pid)

    if not chunks:
        return "No pending chunks."

    project = db.get_project(pid) if pid else None
    header = f"Pending for '{project['name']}' if project else pid:" if pid else "Pending across all projects:"

    docs = {}
    for c in chunks:
        rel = c["rel_path"]
        if rel not in docs:
            docs[rel] = []
        docs[rel].append(c)

    lines = [header, f"  Total: {len(chunks)} chunks\n"]
    for rel_path, doc_chunks in docs.items():
        source = doc_chunks[0].get("source", "file")
        ftype = doc_chunks[0].get("file_type", "")
        tag = f"[{ftype}]" if ftype else ""
        src_tag = f" ({source})" if source != "file" else ""
        lines.append(f"  {rel_path} {tag}{src_tag} ({len(doc_chunks)} chunks)")
        for chunk in doc_chunks[:3]:
            preview = chunk["content"][:120].replace("\n", " ")
            if len(chunk["content"]) > 120:
                preview += "..."
            lines.append(f"    [{chunk['id'][:16]}...] {preview}")
        if len(doc_chunks) > 3:
            lines.append(f"    ... and {len(doc_chunks) - 3} more")
        lines.append("")

    lines.append("  rag_open_approval_ui  - Browser UI")
    lines.append("  rag_approve({chunk_ids: ['__ALL__']})  - Approve all")
    return "\n".join(lines)


def _approve(args):
    ids = args["chunk_ids"]
    db.approve_chunks(ids)
    return "All pending chunks approved." if ids == ["__ALL__"] else f"Approved {len(ids)} chunks."


def _reject(args):
    ids = args["chunk_ids"]
    db.reject_chunks(ids)
    return "All pending chunks rejected." if ids == ["__ALL__"] else f"Rejected {len(ids)} chunks."


def _search(args):
    pid = _resolve_project_id(args)
    query = args["query"]
    top_k = args.get("top_k", 5)

    project = db.get_project(pid)
    if not project:
        return f"Error: Project '{pid}' not found. Use rag_auto_init first."

    indexed = db.get_indexed_chunks(pid, limit=5000)
    if not indexed:
        return (
            f"No indexed knowledge for '{project['name']}'.\n"
            f"  Use rag_auto_scan to index files first."
        )

    index = rag_engine.build_index_from_chunks(indexed)
    results = index.search(query, top_k)

    if not results:
        return f"No results for '{query}' in '{project['name']}'."

    lines = [
        f"Search: '{query}' in '{project['name']}'",
        f"  (searched {len(indexed)} chunks)\n"
    ]
    for i, r in enumerate(results, 1):
        source = ""
        for c in indexed:
            if c["id"] == r["chunk_id"]:
                source = c.get("source", "file")
                break
        src_tag = f" [{source}]" if source != "file" else ""
        preview = r["content"][:300].replace("\n", " ")
        if len(r["content"]) > 300:
            preview += "..."
        lines.append(f"  [{i}] {r['rel_path']}{src_tag} (score: {r['score']})")
        lines.append(f"      {preview}\n")

    return "\n".join(lines)


def _list_projects(args):
    projects = db.list_projects()
    if not projects:
        return "No projects registered. Use rag_auto_init to add one."

    lines = ["Projects in RAG Knowledge Base:\n"]
    for p in projects:
        ptype = p.get("project_type", "")
        type_tag = f" [{ptype}]" if ptype else ""
        lines.append(f"  {p['name']} ({p['id']}){type_tag}")
        lines.append(f"    Path: {p['root_path']}")
        lines.append(f"    Indexed: {p['indexed_count']} | Pending: {p['pending_count']}\n")
    return "\n".join(lines)


def _status(args):
    pid = _resolve_project_id(args)
    project = db.get_project(pid)
    if not project:
        return f"Error: Project '{pid}' not found."

    stats = db.get_project_stats(pid)
    docs = db.get_documents_by_project(pid)

    ptype = project.get("project_type", "")
    type_tag = f"\n  Type: {ptype}" if ptype else ""

    lines = [
        f"Project: {project['name']} ({project['id']}){type_tag}",
        f"  Root: {project['root_path']}",
        f"  Description: {project['description'] or '(none)'}\n",
        f"  Indexed: {stats['indexed']} | Pending: {stats['pending']} | Rejected: {stats['rejected']}",
        f"  Documents: {stats['documents']}\n",
        "Documents:"
    ]
    for d in docs:
        lines.append(f"    {d['rel_path']}")
    return "\n".join(lines)


def _remove_document(args):
    pid = args["project_id"]
    rel = args["rel_path"]
    db.remove_document_by_path(pid, rel)
    return f"Removed '{rel}' from the knowledge base."


def _open_approval_ui(args):
    port = args.get("port", 8765)
    port = web_ui.start_web_server(port)
    url = f"http://127.0.0.1:{port}"

    try:
        import webbrowser
        webbrowser.open(url)
    except Exception:
        pass

    return f"Approval UI at {url}\nReview and approve/reject pending chunks."


def _search_all(args):
    query = args["query"]
    top_k = args.get("top_k_per_project", 3)

    projects = db.list_projects()
    if not projects:
        return "No projects in the knowledge base."

    lines = [f"Search across all projects for '{query}':\n"]
    for p in projects:
        indexed = db.get_indexed_chunks(p["id"], limit=5000)
        if not indexed:
            continue
        index = rag_engine.build_index_from_chunks(indexed)
        results = index.search(query, top_k)
        if results:
            lines.append(f"  {p['name']}:")
            for r in results:
                preview = r["content"][:150].replace("\n", " ")
                if len(r["content"]) > 150:
                    preview += "..."
                lines.append(f"    [{r['rel_path']}] {preview}")
            lines.append("")

    if len(lines) == 2:
        lines.append("  No results found.")

    return "\n".join(lines)


# ---------------------------------------------------------------------------
# Main MCP server loop
# ---------------------------------------------------------------------------
def main():
    db.init_db()
    log("RAG MCP server starting...")

    try:
        web_ui.start_web_server(8765)
        log("Approval UI at http://127.0.0.1:8765")
    except Exception as e:
        log(f"Could not start web UI: {e}")

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
