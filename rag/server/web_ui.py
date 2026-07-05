"""Web UI server for approving/rejecting pending knowledge entries."""

import json
import os
import threading
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


def _category_tag(category):
    return f'<span class="cat-tag cat-{category}">{category}</span>'


def _tag_badges(tags):
    if not tags:
        return ""
    badges = "".join(f'<span class="tag-badge">{t}</span>' for t in tags)
    return f'<div class="tag-list">{badges}</div>'


def _render_projects():
    projects = db.list_projects()
    if not projects:
        return "<p class='empty'>No projects registered yet. Store knowledge to create one.</p>"

    html = []
    for p in projects:
        pending = db.get_pending_entries(p["id"])
        stats = db.get_project_stats(p["id"])

        html.append(f'<div class="project-card" data-project="{p["id"]}">')
        html.append('<div class="project-header">')
        html.append(f'<h2>{p["name"]}</h2>')
        html.append(f'<span class="path">{p["root_path"]}</span>')
        html.append('<div class="stats">')
        html.append(f'<span class="badge indexed">{stats["indexed"]} indexed</span>')
        html.append(f'<span class="badge pending">{stats["pending"]} pending</span>')
        html.append(f'<span class="badge rejected">{stats["rejected"]} rejected</span>')
        html.append(f'<span class="badge total">{stats["total"]} total</span>')
        html.append('</div>')
        html.append('</div>')

        if p.get("description"):
            html.append(f'<p class="desc">{p["description"]}</p>')

        if pending:
            html.append('<div class="actions">')
            html.append(f'<button class="btn approve" onclick="approveAll(\'{p["id"]}\')">Approve All</button>')
            html.append(f'<button class="btn reject" onclick="rejectAll(\'{p["id"]}\')">Reject All</button>')
            html.append('</div>')

            html.append('<div class="pending-list">')
            for entry in pending:
                cat_tag = _category_tag(entry["category"])
                tag_badges = _tag_badges(entry.get("tags", []))
                source_tag = ""
                if entry.get("source") and entry["source"] != "manual":
                    source_tag = f'<span class="source-tag">{entry["source"]}</span>'

                preview = entry["content"][:500]
                if len(entry["content"]) > 500:
                    preview += "..."
                preview = preview.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")

                html.append(f'<div class="entry-item" data-entry="{entry["id"]}">')
                html.append('<div class="entry-header">')
                html.append(f'<span class="entry-title">{entry["title"]}</span>')
                html.append(f'<span class="entry-meta">{cat_tag} {source_tag}</span>')
                html.append('</div>')
                if tag_badges:
                    html.append(tag_badges)
                html.append(f'<div class="entry-preview"><pre>{preview}</pre></div>')
                html.append('<div class="entry-actions">')
                html.append(f'<button class="btn-sm approve" onclick="approveEntry(\'{entry["id"]}\')">Approve</button>')
                html.append(f'<button class="btn-sm reject" onclick="rejectEntry(\'{entry["id"]}\')">Reject</button>')
                html.append('</div>')
                html.append('</div>')
            html.append('</div>')
        else:
            html.append('<p class="empty">No pending entries. Everything is reviewed.</p>')

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
            self._send_json({"pending": db.get_pending_entries(project_id)})
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
            entry_ids = data.get("entry_ids", [])
            db.approve_entries(entry_ids)
            self._send_json({"ok": True, "approved": len(entry_ids) if entry_ids != ["__ALL__"] else "all"})
        elif parsed.path == "/api/reject":
            entry_ids = data.get("entry_ids", [])
            db.reject_entries(entry_ids)
            self._send_json({"ok": True, "rejected": len(entry_ids) if entry_ids != ["__ALL__"] else "all"})
        elif parsed.path == "/api/approve-project":
            project_id = data.get("project_id")
            if project_id:
                pending = db.get_pending_entries(project_id)
                entry_ids = [e["id"] for e in pending]
                db.approve_entries(entry_ids)
                self._send_json({"ok": True, "approved": len(entry_ids)})
            else:
                self._send_json({"error": "project_id required"}, 400)
        elif parsed.path == "/api/reject-project":
            project_id = data.get("project_id")
            if project_id:
                pending = db.get_pending_entries(project_id)
                entry_ids = [e["id"] for e in pending]
                db.reject_entries(entry_ids)
                self._send_json({"ok": True, "rejected": len(entry_ids)})
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
