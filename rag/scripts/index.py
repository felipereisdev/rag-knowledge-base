#!/usr/bin/env python3
"""CLI script to index a project for RAG.

Usage:
    python3 index.py --path /path/to/project --project my-project --name "My Project"
    python3 index.py --path . --project my-project --type python-generic
"""

import argparse
import os
import sys
import webbrowser

# Add server to path
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "server"))

import db
import rag_engine
import web_ui


def main():
    parser = argparse.ArgumentParser(description="Index a project for RAG knowledge base")
    parser.add_argument("--path", required=True, help="Path to project root")
    parser.add_argument("--project", required=True, help="Project ID")
    parser.add_argument("--name", default=None, help="Project name (defaults to directory name)")
    parser.add_argument("--type", default=None, help="Project type override (e.g. python-django, node-next)")
    parser.add_argument("--description", default="", help="Project description")
    parser.add_argument("--max-files", type=int, default=200, help="Max files to scan")
    parser.add_argument("--include", nargs="*", help="Glob patterns to include")
    parser.add_argument("--exclude", nargs="*", help="Glob patterns to exclude")
    parser.add_argument("--skip-tests", action="store_true", help="Skip test files")
    parser.add_argument("--no-skip-generated", action="store_true", help="Don't skip generated files")
    parser.add_argument("--no-ui", action="store_true", help="Don't open approval UI after scan")
    args = parser.parse_args()

    db.init_db()

    root_path = os.path.abspath(args.path)
    if not os.path.isdir(root_path):
        print(f"Error: '{root_path}' is not a valid directory.")
        sys.exit(1)

    # Auto-detect project type if not specified
    project_type = args.type
    type_desc = ""
    if not project_type:
        project_type, type_desc = rag_engine.detect_project_type(root_path)

    name = args.name or os.path.basename(root_path).replace("-", " ").replace("_", " ").title()
    description = args.description or type_desc

    # Init project
    db.upsert_project(args.project, name, root_path, description, project_type or "")
    print(f"Project: {name} ({args.project})")
    print(f"  Type: {project_type or 'unknown'} ({description})")
    print(f"  Root: {root_path}")

    # Get scan strategy
    strategy = rag_engine.get_scan_strategy(project_type)
    include_patterns = args.include or strategy.get("include")
    exclude_patterns = args.exclude or strategy.get("exclude", [])
    skip_tests = args.skip_tests or strategy.get("skip_tests", True)
    skip_generated = not args.no_skip_generated

    # Scan
    files = rag_engine.scan_project(
        root_path,
        max_files=args.max_files,
        include_patterns=include_patterns,
        exclude_patterns=exclude_patterns,
        skip_generated=skip_generated,
        skip_tests=skip_tests,
    )

    if not files:
        print("\nNo files found. Try adjusting patterns with --include.")
        sys.exit(0)

    # Classify
    type_counts = {}
    for f in files:
        ft = f.get("type", "source")
        type_counts[ft] = type_counts.get(ft, 0) + 1

    # Check for new/changed files
    new_files = []
    unchanged = 0
    for f in files:
        file_hash = db.compute_file_hash(f["full_path"])
        existing = db.get_document(args.project, f["rel_path"])
        if existing and existing["file_hash"] == file_hash:
            unchanged += 1
            continue
        new_files.append(f)

    type_summary = " | ".join(f"{k}: {v}" for k, v in sorted(type_counts.items()))
    print(f"\n  Files found: {len(files)} | Types: {type_summary}")
    print(f"  New/changed: {len(new_files)} | Unchanged: {unchanged}")

    if not new_files:
        print("\nAll files already indexed. Use search.py to query.")
        sys.exit(0)

    # Chunk and store
    total_chunks = 0
    for f in new_files:
        content = rag_engine.read_file_content(f["full_path"])
        if not content:
            continue
        chunks = rag_engine.chunk_text(content)
        if not chunks:
            continue

        file_type = f.get("type", "source")
        doc_id = db.upsert_document(args.project, f["rel_path"], db.compute_file_hash(f["full_path"]), file_type)
        n = db.insert_chunks(args.project, doc_id, chunks, file_type)
        total_chunks += n
        print(f"    {f['type']:>8}  {f['rel_path']} ({n} chunks)")

    print(f"\n  Total chunks: {total_chunks} (pending approval)")

    # Open approval UI
    if not args.no_ui:
        port = web_ui.start_web_server(8765)
        url = f"http://127.0.0.1:{port}"
        print(f"\nApproval UI: {url}")
        try:
            webbrowser.open(url)
            print("Opened in browser. Review and approve/reject chunks.")
        except Exception:
            print(f"Open {url} in your browser to review.")

        # Keep server running
        print("\nPress Ctrl+C to stop the approval UI server.")
        try:
            import time
            while True:
                time.sleep(1)
        except KeyboardInterrupt:
            print("\nServer stopped.")


if __name__ == "__main__":
    main()
