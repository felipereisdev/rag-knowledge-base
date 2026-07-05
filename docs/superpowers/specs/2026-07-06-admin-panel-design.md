# RAG Admin Panel — Design Spec

**Date:** 2026-07-06
**Status:** Approved

## Goal

Replace the current single-page approval UI (`web_ui.py` + `approval.html`) with a full admin panel: React SPA + FastAPI REST API, served in separate Docker containers. The panel provides search, CRUD for entries and projects, and the existing approval workflow — all accessible via browser.

## Decisions

| Decision | Choice |
|----------|--------|
| Frontend | React + Vite |
| UI components | shadcn/ui + Tailwind CSS |
| Backend API | FastAPI (uvicorn) |
| Serving | Two Docker containers (nginx for React, uvicorn for FastAPI) |
| Visual theme | shadcn/ui default (dark-capable, component-driven) |
| Auth | None (local tool) |

## Architecture

```
rag/
├── server/
│   ├── main.py              ← MCP server (stdio JSON-RPC, unchanged)
│   ├── api.py               ← NEW: FastAPI REST API
│   ├── db.py                ← existing (CRUD)
│   ├── search_engine.py     ← existing (TF-IDF)
│   ├── doc_import.py        ← existing
│   └── web_ui.py            ← DELETE (replaced by api.py)
├── templates/
│   └── approval.html        ← DELETE (replaced by React SPA)
├── web/                     ← NEW: React frontend
│   ├── package.json
│   ├── vite.config.ts
│   ├── tailwind.config.ts
│   ├── components.json       ← shadcn/ui config
│   ├── index.html
│   └── src/
│       ├── main.tsx
│       ├── App.tsx
│       ├── lib/
│       │   ├── api.ts        ← API client (fetch wrapper)
│       │   └── utils.ts      ← cn() helper for shadcn
│       ├── components/
│       │   └── ui/           ← shadcn/ui components
│       ├── pages/
│       │   ├── Dashboard.tsx
│       │   ├── Projects.tsx
│       │   ├── Entries.tsx
│       │   ├── EntryDetail.tsx
│       │   ├── NewEntry.tsx
│       │   ├── Approvals.tsx
│       │   └── Search.tsx
│       └── components/
│           ├── Layout.tsx     ← sidebar nav + header
│           ├── EntryForm.tsx  ← shared form for create/edit
│           └── EntryTable.tsx ← shared table for entries
├── Dockerfile.api            ← NEW: Python 3.12 + FastAPI
├── Dockerfile.web            ← NEW: Node build → nginx
├── docker-compose.yml        ← updated: 2 services
└── nginx.conf                ← NEW: nginx config for SPA + proxy
```

### Container topology

```
Browser (:8765) → nginx container → /api/* → FastAPI container (:8000)
                                → /*     → React static files
```

- `rag-api` container: FastAPI + uvicorn, port 8000, volume `~/.rag:/root/.rag`
- `rag-web` container: nginx serving React build, port 8765, proxies `/api/*` to `rag-api:8000`

### Process coexistence

`main.py` (MCP server) starts both:
1. FastAPI in a daemon thread (replaces `web_ui.start_web_server`)
2. MCP stdio loop (unchanged)

The MCP server and REST API share the same `db.py` layer. No conflict — SQLite WAL mode handles concurrent reads; writes are serialized by SQLite's locking.

## Backend: FastAPI API (`server/api.py`)

### Endpoints

#### Projects

| Method | Route | Description |
|--------|-------|-------------|
| GET | `/api/projects` | List all projects (with stats) |
| POST | `/api/projects` | Create project |
| GET | `/api/projects/{id}` | Get project detail |
| PUT | `/api/projects/{id}` | Update project (name, description, language) |
| DELETE | `/api/projects/{id}` | Delete project and all its entries |
| GET | `/api/projects/{id}/stats` | Project stats (indexed, pending, rejected, total) |
| POST | `/api/projects/{id}/approve-all` | Approve all pending entries |
| POST | `/api/projects/{id}/reject-all` | Reject all pending entries |

#### Entries

| Method | Route | Description |
|--------|-------|-------------|
| GET | `/api/entries` | List entries (query params: project_id, category, tags, status, limit) |
| GET | `/api/entries/{id}` | Get entry detail (with tags) |
| POST | `/api/entries` | Create entry (title, content, category, tags, project_id) |
| PUT | `/api/entries/{id}` | Update entry (title, content, category, tags) |
| DELETE | `/api/entries/{id}` | Delete entry |
| POST | `/api/entries/{id}/approve` | Approve entry |
| POST | `/api/entries/{id}/reject` | Reject entry |

#### Search

| Method | Route | Description |
|--------|-------|-------------|
| GET | `/api/search` | Search entries (query params: q, project_id, category, tags, top_k) |
| GET | `/api/tags` | List tags for a project (query param: project_id) |

### Request/Response format

All responses are JSON. Entry format:

```json
{
  "id": "uuid",
  "project_id": "refresh",
  "title": "Order approval rule",
  "content": "Orders over 1000 require manager approval",
  "category": "business-rule",
  "source": "manual",
  "author": "",
  "status": "indexed",
  "tags": ["orders", "approval"],
  "metadata": {},
  "created_at": 1720000000.0,
  "updated_at": 1720000000.0
}
```

### CORS

FastAPI enables CORS for all origins (local tool, no auth).

### Dependencies

```
fastapi
uvicorn[standard]
```

Added to a `requirements.txt` in `rag/server/`.

### Startup

`api.py` exposes both:

1. `app` — the FastAPI app object (used by uvicorn directly in Docker)
2. `start_api_server(port)` — starts uvicorn in a daemon thread (used by `main.py` when running as MCP server)

```python
def start_api_server(port=8000):
    # Start uvicorn in a daemon thread
    # Returns the port
```

**Two run modes:**
- **MCP server mode** (`main.py`): starts FastAPI in a thread + runs MCP stdio loop. Used by assistants.
- **Standalone API mode** (Docker `Dockerfile.api`): runs `uvicorn server.api:app` directly. Used by the web panel container.

`main.py` calls `api.start_api_server(8000)` instead of `web_ui.start_web_server(8765)`.

## Frontend: React + Vite + shadcn/ui

### Pages

#### 1. Dashboard (`/`)

- Global stats: total projects, total entries, pending count
- Recent entries (last 5)
- Quick links to approvals and search

#### 2. Projects (`/projects`)

- Table: name, path, language, indexed/pending counts
- Create project dialog (name, root_path, description, language)
- Edit project dialog (name, description, language)
- Delete project (with confirmation)

#### 3. Entries (`/entries`)

- DataTable with columns: title, category, tags, status, created_at
- Filters: project (select), category (select), status (tabs: all/pending/indexed/rejected), tags (multi-select)
- Pagination (client-side, 20 per page)
- Row actions: view, edit, delete
- "New Entry" button

#### 4. Entry Detail (`/entries/:id`)

- Full entry view: title, content (preformatted), category, tags, status, metadata
- Edit mode: inline form (title, content textarea, category select, tags input)
- Delete button (with confirmation dialog)
- Approve/Reject buttons (if pending)

#### 5. New Entry (`/entries/new`)

- Form: project (select), title, content (textarea), category (select), tags (input)
- Submit creates entry in pending status
- Redirects to entry detail on success

#### 6. Approvals (`/approvals`)

- Grouped by project
- Each pending entry shows: title, category badge, tags, content preview, source
- Approve/Reject buttons per entry
- Approve All / Reject All per project
- No page reload — updates state via API response

#### 7. Search (`/search`)

- Search input with debounce (300ms)
- Filters: project, category, tags
- Results: ranked list with score, title, category, tags, content preview
- Click result → entry detail

### Shared components

- `Layout.tsx` — sidebar with nav links (Dashboard, Projects, Entries, Approvals, Search), header with project selector
- `EntryForm.tsx` — reusable form for create/edit entry (used in NewEntry and EntryDetail edit mode)
- `EntryTable.tsx` — reusable table for entries (used in Entries and Approvals)

### shadcn/ui components used

- `Button`, `Input`, `Textarea`, `Select`, `Label`
- `Table` (DataTable)
- `Dialog` (confirmations, create/edit modals)
- `Tabs` (status filter)
- `Badge` (categories, tags, status)
- `Command` (search)
- `Toast` (notifications on create/update/delete)
- `Sidebar` (navigation)
- `Card` (dashboard stats, entry cards)

### API client (`src/lib/api.ts`)

Fetch wrapper with typed functions:

```typescript
const API_BASE = '/api';

export async function listProjects(): Promise<Project[]> { ... }
export async function createProject(data: ProjectCreate): Promise<Project> { ... }
export async function listEntries(params: EntryFilters): Promise<Entry[]> { ... }
export async function getEntry(id: string): Promise<Entry> { ... }
export async function createEntry(data: EntryCreate): Promise<Entry> { ... }
export async function updateEntry(id: string, data: EntryUpdate): Promise<Entry> { ... }
export async function deleteEntry(id: string): Promise<void> { ... }
export async function approveEntry(id: string): Promise<void> { ... }
export async function rejectEntry(id: string): Promise<void> { ... }
export async function searchEntries(params: SearchParams): Promise<SearchResult[]> { ... }
```

### Routing

`react-router-dom` with `createBrowserRouter`:

```
/              → Dashboard
/projects      → Projects
/entries       → Entries
/entries/new   → NewEntry
/entries/:id   → EntryDetail
/approvals     → Approvals
/search        → Search
```

## Docker

### Dockerfile.api

```dockerfile
FROM python:3.12-slim
WORKDIR /app
COPY rag/server/ /app/server/
COPY requirements.txt /app/
RUN pip install --no-cache-dir -r requirements.txt
EXPOSE 8000
CMD ["uvicorn", "server.api:app", "--host", "0.0.0.0", "--port", "8000"]
```

### Dockerfile.web

```dockerfile
FROM node:20-slim AS build
WORKDIR /app
COPY rag/web/package*.json ./
RUN npm ci
COPY rag/web/ ./
RUN npm run build

FROM nginx:alpine
COPY --from=build /app/dist /usr/share/nginx/html
COPY nginx.conf /etc/nginx/conf.d/default.conf
EXPOSE 80
```

### nginx.conf

```nginx
server {
    listen 80;
    root /usr/share/nginx/html;
    index index.html;

    location /api/ {
        proxy_pass http://rag-api:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }

    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

### docker-compose.yml

```yaml
services:
  api:
    build:
      context: .
      dockerfile: Dockerfile.api
    container_name: rag-api
    ports:
      - "8000:8000"
    volumes:
      - ~/.rag:/root/.rag
    restart: unless-stopped

  web:
    build:
      context: .
      dockerfile: Dockerfile.web
    container_name: rag-web
    ports:
      - "8765:80"
    depends_on:
      - api
    restart: unless-stopped
```

## Migration

1. Create `server/api.py` with all endpoints
2. Create `web/` React project with Vite + shadcn/ui
3. Update `main.py` to start FastAPI instead of `web_ui`
4. Update `rag_open_approval_ui` tool to return `http://127.0.0.1:8765`
5. Delete `web_ui.py` and `templates/approval.html`
6. Create `Dockerfile.api`, `Dockerfile.web`, `nginx.conf`, update `docker-compose.yml`
7. Update tests — replace web_ui tests with api tests
8. Update README with new architecture

## Testing

- **Backend:** pytest tests for FastAPI endpoints (`tests/test_api.py`) using FastAPI TestClient
- **Frontend:** manual verification (no test framework for React in this scope)
- **Integration:** verify Docker compose brings up both containers and the panel works end-to-end

## Out of scope

- Authentication / authorization
- User management
- Full-text search beyond TF-IDF
- Real-time updates (websockets)
- Export/import of knowledge base
- Dark/light theme toggle (shadcn default only)
