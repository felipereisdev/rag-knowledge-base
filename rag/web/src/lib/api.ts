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
  root_path: string;
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

  listEntries: (params: { project_id: string; category?: string; status?: string; tags?: string[] }) => {
    const search = new URLSearchParams({ project_id: params.project_id });
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

  search: (params: { q: string; project_id: string; category?: string; tags?: string[]; top_k?: number }) => {
    const search = new URLSearchParams({ q: params.q, project_id: params.project_id });
    if (params.category) search.set("category", params.category);
    if (params.tags) params.tags.forEach((t) => search.append("tags", t));
    if (params.top_k) search.set("top_k", String(params.top_k));
    return fetchJSON<SearchResult[]>(`/search?${search}`);
  },
  listTags: (projectId: string) =>
    fetchJSON<string[]>(`/tags?project_id=${projectId}`),
};
