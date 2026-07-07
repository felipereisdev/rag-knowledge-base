#!/usr/bin/env python3
"""CLI script to search the knowledge base.

Usage:
    python3 search.py --query "authentication flow" --project my-project
    python3 search.py --query "database" --project my-project --top-k 10
    python3 search.py --query "business rules" --project my-project --category business-rule
"""

import argparse
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "server"))

import db
import embeddings


def main():
    parser = argparse.ArgumentParser(description="Search the knowledge base")
    parser.add_argument("--query", "-q", required=True, help="Search query")
    parser.add_argument("--project", "-p", required=True, help="Project ID")
    parser.add_argument("--category", default=None, help="Filter by category")
    parser.add_argument("--tags", nargs="*", default=None, help="Filter by tags")
    parser.add_argument("--top-k", "-k", type=int, default=5, help="Number of results")
    args = parser.parse_args()

    db.init_db()

    project = db.get_project(args.project)
    if not project:
        print(f"Error: Project '{args.project}' not found.")
        sys.exit(1)

    entries = db.get_indexed_entries(args.project)
    if not entries:
        print(f"No indexed knowledge for '{project['name']}'. Store and approve entries first.")
        sys.exit(0)

    query_vec = embeddings.embed_query(args.query)
    results = db.search_entries_by_embedding(
        query_vec, project_id=args.project, k=args.top_k, category=args.category, tags=args.tags,
    )

    if not results:
        print(f"No results for '{args.query}' in '{project['name']}'.")
        sys.exit(0)

    print(f"Search: '{args.query}' in '{project['name']}'")
    print(f"  (searched {len(entries)} entries)\n")
    for i, r in enumerate(results, 1):
        tags_str = f" [{', '.join(r['tags'])}]" if r.get("tags") else ""
        preview = r["content"][:300].replace("\n", " ")
        if len(r["content"]) > 300:
            preview += "..."
        print(f"  [{i}] {r['title']} ({r['category']}){tags_str} (score: {r['score']})")
        print(f"      {preview}\n")


if __name__ == "__main__":
    main()
