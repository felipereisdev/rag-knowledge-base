#!/usr/bin/env python3
"""CLI script to store a knowledge entry.

Usage:
    python3 store.py --project my-project --title "Order rule" --content "Orders over 1000 need approval"
    python3 store.py --project my-project --title "Auth architecture" --content "..." --category architecture --tags auth security
"""

import argparse
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "server"))

import db
import api


def main():
    parser = argparse.ArgumentParser(description="Store a knowledge entry")
    parser.add_argument("--project", "-p", required=True, help="Project ID")
    parser.add_argument("--title", "-t", required=True, help="Title of the knowledge entry")
    parser.add_argument("--content", "-c", required=True, help="Content/body of the knowledge entry")
    parser.add_argument("--category", "-k", default="insight",
                        choices=["business-rule", "design-decision", "architecture",
                                 "documentation", "insight", "convention", "constraint"],
                        help="Category of knowledge")
    parser.add_argument("--tags", nargs="*", default=[], help="Tags for the entry")
    parser.add_argument("--no-ui", action="store_true", help="Don't open approval UI")
    args = parser.parse_args()

    db.init_db()

    project = db.get_project(args.project)
    if not project:
        print(f"Error: Project '{args.project}' not found. Run import.py --init first.")
        sys.exit(1)

    entry_id = db.store_knowledge_entry(
        project_id=args.project,
        title=args.title,
        content=args.content,
        category=args.category,
        source="manual",
        tags=args.tags,
    )

    print(f"Knowledge entry stored for '{project['name']}'.")
    print(f"  Title: {args.title}")
    print(f"  Category: {args.category}")
    print(f"  Tags: {', '.join(args.tags) if args.tags else '(none)'}")
    print(f"  ID: {entry_id}")
    print(f"  Status: pending (needs approval)")

    if not args.no_ui:
        api.start_api_server(8000)
        print(f"\nAdmin panel API: http://127.0.0.1:8000")
        try:
            import webbrowser
            webbrowser.open("http://127.0.0.1:8000")
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
