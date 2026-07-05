#!/usr/bin/env python3
"""CLI script to import a markdown or text file into the knowledge base.

Usage:
    python3 import.py --file notes.md --project my-project
    python3 import.py --file rules.md --project my-project --category business-rule --tags orders approval
    python3 import.py --file doc.md --project my-project --init --name "My Project"
"""

import argparse
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "server"))

import db
import doc_import
import web_ui


def main():
    parser = argparse.ArgumentParser(description="Import a document into the knowledge base")
    parser.add_argument("--file", "-f", required=True, help="Path to .md or .txt file")
    parser.add_argument("--project", "-p", required=True, help="Project ID")
    parser.add_argument("--name", default=None, help="Project name (for --init)")
    parser.add_argument("--init", action="store_true", help="Initialize the project if it doesn't exist")
    parser.add_argument("--category", default="insight",
                        choices=["business-rule", "design-decision", "architecture",
                                 "documentation", "insight", "convention", "constraint"],
                        help="Default category for imported entries")
    parser.add_argument("--tags", nargs="*", default=None, help="Default tags for imported entries")
    parser.add_argument("--no-ui", action="store_true", help="Don't open approval UI")
    args = parser.parse_args()

    db.init_db()

    project = db.get_project(args.project)
    if not project:
        if args.init:
            name = args.name or args.project
            root_path = os.path.dirname(os.path.abspath(args.file))
            db.upsert_project(args.project, name, root_path, "", "")
            project = db.get_project(args.project)
            print(f"Created project: {project['name']} ({args.project})")
        else:
            print(f"Error: Project '{args.project}' not found. Use --init to create it.")
            sys.exit(1)

    filepath = os.path.abspath(args.file)
    if not os.path.isfile(filepath):
        print(f"Error: '{filepath}' is not a valid file.")
        sys.exit(1)

    entry_ids = doc_import.import_document(db, args.project, filepath, args.category, args.tags)

    print(f"\nImported {len(entry_ids)} entries from {filepath}")
    print(f"  Project: {project['name']}")
    print(f"  Status: pending (needs approval)")

    if not args.no_ui:
        port = web_ui.start_web_server(8765)
        print(f"\nApproval UI: http://127.0.0.1:{port}")
        try:
            import webbrowser
            webbrowser.open(f"http://127.0.0.1:{port}")
        except Exception:
            pass
        print("Press Ctrl+C to stop.")
        try:
            import time
            while True:
                time.sleep(1)
        except KeyboardInterrupt:
            print("\nServer stopped.")


if __name__ == "__main__":
    main()
