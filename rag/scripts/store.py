#!/usr/bin/env python3
"""CLI script to store a knowledge entry.

Usage:
    python3 store.py --project my-project --title "Order rule" --content "Orders over 1000 need approval"
    python3 store.py --project my-project --title "Auth architecture" --content "..." --category architecture
"""

import argparse
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "server"))

import db
import web_ui


def main():
    parser = argparse.ArgumentParser(description="Store a knowledge entry")
    parser.add_argument("--project", "-p", required=True, help="Project ID")
    parser.add_argument("--title", "-t", required=True, help="Title of the knowledge entry")
    parser.add_argument("--content", "-c", required=True, help="Content/body of the knowledge entry")
    parser.add_argument("--category", "-k", default="insight",
                        choices=["business-rule", "design-decision", "architecture",
                                 "constraint", "convention", "insight"],
                        help="Category of knowledge")
    parser.add_argument("--no-ui", action="store_true", help="Don't open approval UI")
    args = parser.parse_args()

    db.init_db()

    project = db.get_project(args.project)
    if not project:
        print(f"Error: Project '{args.project}' not found. Run index.py --init first.")
        sys.exit(1)

    full_content = f"[{args.category.upper()}] {args.title}\n\n{args.content}"
    chunk_id = db.insert_knowledge_chunk(args.project, args.title, full_content, source=args.category)

    print(f"Knowledge entry stored for '{project['name']}'.")
    print(f"  Title: {args.title}")
    print(f"  Category: {args.category}")
    print(f"  Chunk: {chunk_id}")
    print(f"  Status: pending (needs approval)")

    if not args.no_ui:
        port = web_ui.start_web_server(8765)
        print(f"\nApproval UI: http://127.0.0.1:{port}")
        print("Open in browser to approve/reject.")
        print("Press Ctrl+C to stop.")

        try:
            import webbrowser
            webbrowser.open(f"http://127.0.0.1:{port}")
        except Exception:
            pass

        try:
            import time
            while True:
                time.sleep(1)
        except KeyboardInterrupt:
            print("\nServer stopped.")


if __name__ == "__main__":
    main()
