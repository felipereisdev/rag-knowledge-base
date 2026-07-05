#!/usr/bin/env python3
"""CLI script to search the RAG knowledge base.

Usage:
    python3 search.py --query "authentication flow" --project my-project
    python3 search.py --query "database" --project my-project --top-k 10
    python3 search.py --query "business rules" --all  # search all projects
"""

import argparse
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "server"))

import db
import rag_engine
import web_ui


def main():
    parser = argparse.ArgumentParser(description="Search the RAG knowledge base")
    parser.add_argument("--query", "-q", required=True, help="Search query")
    parser.add_argument("--project", "-p", default=None, help="Project ID")
    parser.add_argument("--all", "-a", action="store_true", help="Search all projects")
    parser.add_argument("--top-k", "-k", type=int, default=5, help="Number of results")
    args = parser.parse_args()

    db.init_db()

    if args.all:
        projects = db.list_projects()
        if not projects:
            print("No projects in the knowledge base.")
            sys.exit(0)

        print(f"Search across all projects for: '{args.query}'\n")
        for p in projects:
            indexed = db.get_indexed_chunks(p["id"], limit=5000)
            if not indexed:
                continue
            index = rag_engine.build_index_from_chunks(indexed)
            results = index.search(args.query, args.top_k)
            if results:
                print(f"  [{p['name']}] ({p['project_type'] if p.get('project_type') else ''})")
                for r in results:
                    preview = r["content"][:200].replace("\n", " ")
                    if len(r["content"]) > 200:
                        preview += "..."
                    print(f"    [{r['rel_path']}] (score: {r['score']})")
                    print(f"      {preview}\n")
    else:
        if not args.project:
            print("Error: --project is required (or use --all to search all)")
            sys.exit(1)

        project = db.get_project(args.project)
        if not project:
            print(f"Error: Project '{args.project}' not found.")
            sys.exit(1)

        indexed = db.get_indexed_chunks(args.project, limit=5000)
        if not indexed:
            print(f"No indexed knowledge for '{project['name']}'. Run index.py first.")
            sys.exit(0)

        index = rag_engine.build_index_from_chunks(indexed)
        results = index.search(args.query, args.top_k)

        if not results:
            print(f"No results for '{args.query}' in '{project['name']}'.")
            sys.exit(0)

        ptype = f" ({project.get('project_type', '')})" if project.get('project_type') else ""
        print(f"Search: '{args.query}' in '{project['name']}'{ptype}")
        print(f"  (searched {len(indexed)} chunks)\n")
        for i, r in enumerate(results, 1):
            preview = r["content"][:300].replace("\n", " ")
            if len(r["content"]) > 300:
                preview += "..."
            print(f"  [{i}] {r['rel_path']} (score: {r['score']})")
            print(f"      {preview}\n")


if __name__ == "__main__":
    main()
