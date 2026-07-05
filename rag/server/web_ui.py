"""Web UI server for approving/rejecting pending RAG knowledge chunks."""

import json
import os
import threading
import time
from http.server import HTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse, parse_qs

import db

TEMPLATE_DIR = os.path.join(os.path.dirname(__file__), "templates")

_server_instance = None
_server_port = None
_server_lock = threading.Lock()


def _load_template():
    path = os.path.join(TEMPLATE_DIR, "approval.html")
    with open(path, "r", encoding="utf-8") as f:
        return f.read()


def _type_tag(file_type):
    """Render a colored type tag."""
    return f'<span class="type-tag type-{file_type}">{file_type}</span>'


def _render_projects():
    projects = db.list_projects()
    if not projects:
        return "<p class='empty'>No projects registered yet. Use the <code>rag_init_project</code> tool to add one.</p>"

    html = []
    for p in projects:
        stats = db.get_project_stats(p["id"])
        pending = db.get_pending_chunks(p["id"])
        pending_count = len(pending)

        html.append(f'<div class="project-card" data-project="{p["id"]}">')
        html.append(f'<div class="project-header">')
        html.append(f'<h2>{p["name"]}</h2>')
        html.append(f'<span class="path">{p["root_path"]}</span>')
        html.append(f'<div class="stats">')
        html.append(f'<span class="badge indexed">{stats["indexed"]} indexed</span>')
        html.append(f'<span class="badge pending">{stats["pending"]} pending</span>')
        html.append(f'<span class="badge rejected">{stats["rejected"]} rejected</span>')
        html.append(f'<span class="badge docs">{stats["documents"]} docs</span>')
        html.append(f'</div>')
        html.append(f'</div>')

        if p.get("description"):
            html.append(f'<p class="desc">{p["description"]}</p>')

        if pending:
            html.append('<div class="actions">')
            html.append(f'<button class="btn approve" onclick="approveAll(\'{p["id"]}\')">Approve All</button>')
            html.append(f'<button class="btn reject" onclick="rejectAll(\'{p["id"]}\')">Reject All</button>')
            html.append('</div>')

            # Group pending chunks by document
            docs = {}
            for chunk in pending:
                rel = chunk["rel_path"]
                if rel not in docs:
                    docs[rel] = []
                docs[rel].append(chunk)

            html.append('<div class="pending-list">')
            for rel_path, chunks in docs.items():
                file_type = chunks[0].get("file_type", "source")
                tag = _type_tag(file_type)
                html.append(f'<div class="doc-group">')
                html.append(f'<div class="doc-header">')
                html.append(f'<span class="doc-path">{rel_path}</span>')
                html.append(f'<span class="chunk-count">{tag} {len(chunks)} chunks</span>')
                html.append(f'</div>')
                for chunk in chunks:
                    preview = chunk["content"][:300]
                    if len(chunk["content"]) > 300:
                        preview += "..."
                    # Escape for HTML
                    preview = preview.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
                    html.append(f'<div class="chunk-item" data-chunk="{chunk["id"]}">')
                    html.append(f'<div class="chunk-preview"><pre>{preview}</pre></div>')
                    html.append(f'<div class="chunk-actions">')
                    html.append(f'<button class="btn-sm approve" onclick="approveChunk(\'{chunk["id"]}\')">Approve</button>')
                    html.append(f'<button class="btn-sm reject" onclick="rejectChunk(\'{chunk["id"]}\')">Reject</button>')
                    html.append(f'</div>')
                    html.append(f'</div>')
                html.append(f'</div>')
            html.append('</div>')
        else:
            html.append('<p class="empty">No pending chunks. Use <code>rag_scan_files</code> to index new content.</p>')

        html.append('</div>')

    return "\n".join(html)


def _render_page():
    template = _load_template()
    projects_html = _render_projects()
    return template.replace("{{PROJECTS}}", projects_html)


class ApprovalHandler(BaseHTTPRequestHandler):
    def log_message(self, format, *args):
        pass

    def _send_json(self, data, code=200):
        body = json.dumps(data).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _send_html(self, html, code=200):
        body = html.encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        parsed = urlparse(self.path)
        if parsed.path == "/" or parsed.path == "/index.html":
            self._send_html(_render_page())
        elif parsed.path == "/api/projects":
            self._send_json({"projects": db.list_projects()})
        elif parsed.path == "/api/pending":
            qs = parse_qs(parsed.query)
            project_id = qs.get("project", [None])[0]
            self._send_json({"pending": db.get_pending_chunks(project_id)})
        else:
            self.send_error(404)

    def do_POST(self):
        parsed = urlparse(self.path)
        content_length = int(self.headers.get("Content-Length", 0))
        body = self.rfile.read(content_length) if content_length else b"{}"

        try:
            data = json.loads(body) if body else {}
        except json.JSONDecodeError:
            self._send_json({"error": "Invalid JSON"}, 400)
            return

        if parsed.path == "/api/approve":
            chunk_ids = data.get("chunk_ids", [])
            db.approve_chunks(chunk_ids)
            self._send_json({"ok": True, "approved": len(chunk_ids) if chunk_ids != ["__ALL__"] else "all"})
        elif parsed.path == "/api/reject":
            chunk_ids = data.get("chunk_ids", [])
            db.reject_chunks(chunk_ids)
            self._send_json({"ok": True, "rejected": len(chunk_ids) if chunk_ids != ["__ALL__"] else "all"})
        elif parsed.path == "/api/approve-project":
            project_id = data.get("project_id")
            if project_id:
                db.approve_chunks(["__ALL__"])
                self._send_json({"ok": True})
            else:
                self._send_json({"error": "project_id required"}, 400)
        elif parsed.path == "/api/reject-project":
            project_id = data.get("project_id")
            if project_id:
                db.reject_chunks(["__ALL__"])
                self._send_json({"ok": True})
            else:
                self._send_json({"error": "project_id required"}, 400)
        else:
            self.send_error(404)


def start_web_server(port=8765):
    global _server_instance, _server_port
    with _server_lock:
        if _server_instance is not None:
            return _server_port
        server = HTTPServer(("127.0.0.1", port), ApprovalHandler)
        _server_instance = server
        _server_port = port
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        return _server_port


def stop_web_server():
    global _server_instance, _server_port
    with _server_lock:
        if _server_instance is not None:
            _server_instance.shutdown()
            _server_instance = None
            _server_port = None
