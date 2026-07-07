const API_BASE = "/api";

export interface Project {
  id: string;
  name: string;
  root_path: string;
  description: string;
  project_type: string;
  language: string;
  created_at: number;
  updated_at: number;
  indexed_count?: number;
  pending_count?: number;
  paths?: string[];
}

export interface Entry {
  id: string;
  project_id: string;
  title: string;
  content: string;
  category: string;
  source: string;
  author: string;
  status: string;
  tags: string[];
  metadata: Record<string, unknown>;
  created_at: number;
  updated_at: number;
}

export interface SearchResult {
  id: string;
  title: string;
  content: string;
  category: string;
  tags: string[];
  score: number;
}

export interface ProjectCreate {
  id: string;
  name: string;
  root_path?: string;
  paths?: string[];
  description?: string;
  language?: string;
}

export interface EntryCreate {
  project_id: string;
  title: string;
  content: string;
  category?: string;
  tags?: string[];
}

export interface EntryUpdate {
  title?: string;
  content?: string;
  category?: string;
  tags?: string[];
}

export interface Entity {
  id: number;
  name: string;
  type: string;
  entry_count: number;
}

export interface Relation {
  id: number;
  subject_id: number;
  object_id: number;
  predicate: string;
  entry_id: string | null;
}

export interface GraphData {
  entities: Entity[];
  relations: Relation[];
}

export interface Triple {
  subject: string;
  predicate: string;
  object: string;
  entry_id: string | null;
}

export interface EntityDetail {
  id: number;
  project_id: string;
  name: string;
  norm_name: string;
  type: string;
  created_at: number;
}

export interface EntityGraph {
  entity: EntityDetail | null;
  triples: Triple[];
  entries: Entry[];
}

export interface EntryEntity {
  id: number;
  name: string;
  type: string;
}

export interface EntryRelation {
  id: number;
  subject: string;
  predicate: string;
  object: string;
}

export interface EntryLink {
  from_entry: string;
  to_entry: string;
  relation: string;
}

export interface EntryGraph {
  entities: EntryEntity[];
  relations: EntryRelation[];
  links: EntryLink[];
}

export interface SearchGraph {
  triples: Triple[];
  related_entries: Entry[];
}

async function fetchJSON<T>(url: string, options?: RequestInit): Promise<T> {
  const resp = await fetch(`${API_BASE}${url}`, {
    headers: { "Content-Type": "application/json" },
    ...options,
  });
  if (!resp.ok) {
    const text = await resp.text();
    throw new Error(text || resp.statusText);
  }
  if (resp.status === 204) return undefined as T;
  return resp.json();
}

interface SearchParams {
  q: string;
  project_id?: string;
  category?: string;
  tags?: string[];
  top_k?: number;
  depth?: number;
}

function search(params: SearchParams & { expand: true }): Promise<{ results: SearchResult[]; graph: SearchGraph }>;
function search(params: SearchParams & { expand?: false }): Promise<SearchResult[]>;
function search(
  params: SearchParams & { expand?: boolean }
): Promise<SearchResult[] | { results: SearchResult[]; graph: SearchGraph }> {
  const qs = new URLSearchParams({ q: params.q });
  if (params.project_id) qs.set("project_id", params.project_id);
  if (params.category) qs.set("category", params.category);
  if (params.tags) params.tags.forEach((t) => qs.append("tags", t));
  if (params.top_k) qs.set("top_k", String(params.top_k));
  if (params.expand) qs.set("expand", "true");
  if (params.depth) qs.set("depth", String(params.depth));
  return fetchJSON<SearchResult[] | { results: SearchResult[]; graph: SearchGraph }>(`/search?${qs}`);
}

export const api = {
  listProjects: () => fetchJSON<Project[]>("/projects"),
  getProject: (id: string) => fetchJSON<Project>(`/projects/${id}`),
  createProject: (data: ProjectCreate) =>
    fetchJSON<Project>("/projects", { method: "POST", body: JSON.stringify(data) }),
  updateProject: (id: string, data: Partial<ProjectCreate>) =>
    fetchJSON<Project>(`/projects/${id}`, { method: "PUT", body: JSON.stringify(data) }),
  deleteProject: (id: string) =>
    fetchJSON<void>(`/projects/${id}`, { method: "DELETE" }),
  projectStats: (id: string) =>
    fetchJSON<{ indexed: number; pending: number; rejected: number; total: number }>(`/projects/${id}/stats`),
  approveAll: (id: string) =>
    fetchJSON<{ ok: boolean }>(`/projects/${id}/approve-all`, { method: "POST" }),
  rejectAll: (id: string) =>
    fetchJSON<{ ok: boolean }>(`/projects/${id}/reject-all`, { method: "POST" }),
  addProjectPath: (projectId: string, path: string) =>
    fetchJSON<Project>(`/projects/${projectId}/paths`, { method: "POST", body: JSON.stringify({ path }) }),
  removeProjectPath: (projectId: string, path: string) =>
    fetchJSON<void>(`/projects/${projectId}/paths?path=${encodeURIComponent(path)}`, { method: "DELETE" }),

  listEntries: (params: { project_id?: string; category?: string; status?: string; tags?: string[] }) => {
    const search = new URLSearchParams();
    if (params.project_id) search.set("project_id", params.project_id);
    if (params.category) search.set("category", params.category);
    if (params.status) search.set("status", params.status);
    if (params.tags) params.tags.forEach((t) => search.append("tags", t));
    return fetchJSON<Entry[]>(`/entries?${search}`);
  },
  getEntry: (id: string) => fetchJSON<Entry>(`/entries/${id}`),
  createEntry: (data: EntryCreate) =>
    fetchJSON<Entry>("/entries", { method: "POST", body: JSON.stringify(data) }),
  updateEntry: (id: string, data: EntryUpdate) =>
    fetchJSON<Entry>(`/entries/${id}`, { method: "PUT", body: JSON.stringify(data) }),
  deleteEntry: (id: string) => fetchJSON<void>(`/entries/${id}`, { method: "DELETE" }),
  approveEntry: (id: string) =>
    fetchJSON<{ ok: boolean }>(`/entries/${id}/approve`, { method: "POST" }),
  rejectEntry: (id: string) =>
    fetchJSON<{ ok: boolean }>(`/entries/${id}/reject`, { method: "POST" }),

  search,
  listTags: (projectId: string) =>
    fetchJSON<string[]>(`/tags?project_id=${projectId}`),

  getGraph: (projectId: string) =>
    fetchJSON<GraphData>(`/graph?project_id=${encodeURIComponent(projectId)}`),
  getEntityGraph: (projectId: string, name: string, depth = 1) =>
    fetchJSON<EntityGraph>(
      `/graph/entity?project_id=${encodeURIComponent(projectId)}&name=${encodeURIComponent(name)}&depth=${depth}`
    ),
  getEntryGraph: (entryId: string) =>
    fetchJSON<EntryGraph>(`/entries/${entryId}/graph`),
};
