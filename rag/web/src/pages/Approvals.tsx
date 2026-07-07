import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import ReactMarkdown from "react-markdown";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Card, CardHeader, CardTitle, CardContent } from "@/components/ui/card";
import { api, type Project, type Entry } from "@/lib/api";

export default function Approvals() {
  const navigate = useNavigate();
  const [projects, setProjects] = useState<Project[]>([]);
  const [pendingByProject, setPendingByProject] = useState<Record<string, Entry[]>>({});
  const [loading, setLoading] = useState(true);
  const [actingIds, setActingIds] = useState<Record<string, "approve" | "reject">>({});
  const [actingProject, setActingProject] = useState<Record<string, "approve" | "reject">>({});

  async function load() {
    try {
      const projs = await api.listProjects();
      setProjects(projs);
      const pendingMap: Record<string, Entry[]> = {};
      for (const p of projs) {
        const entries = await api.listEntries({ project_id: p.id, status: "pending" });
        if (entries.length > 0) {
          pendingMap[p.id] = entries;
        }
      }
      setPendingByProject(pendingMap);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(); }, []);

  async function approveEntry(id: string, projectId: string) {
    setActingIds((m) => ({ ...m, [id]: "approve" }));
    try {
      await api.approveEntry(id);
      const updated = pendingByProject[projectId].filter((e) => e.id !== id);
      setPendingByProject({ ...pendingByProject, [projectId]: updated });
    } finally {
      setActingIds((m) => { const c = { ...m }; delete c[id]; return c; });
    }
  }

  async function rejectEntry(id: string, projectId: string) {
    setActingIds((m) => ({ ...m, [id]: "reject" }));
    try {
      await api.rejectEntry(id);
      const updated = pendingByProject[projectId].filter((e) => e.id !== id);
      setPendingByProject({ ...pendingByProject, [projectId]: updated });
    } finally {
      setActingIds((m) => { const c = { ...m }; delete c[id]; return c; });
    }
  }

  async function approveAll(projectId: string) {
    setActingProject((m) => ({ ...m, [projectId]: "approve" }));
    try {
      await api.approveAll(projectId);
      setPendingByProject({ ...pendingByProject, [projectId]: [] });
    } finally {
      setActingProject((m) => { const c = { ...m }; delete c[projectId]; return c; });
    }
  }

  async function rejectAll(projectId: string) {
    setActingProject((m) => ({ ...m, [projectId]: "reject" }));
    try {
      await api.rejectAll(projectId);
      setPendingByProject({ ...pendingByProject, [projectId]: [] });
    } finally {
      setActingProject((m) => { const c = { ...m }; delete c[projectId]; return c; });
    }
  }

  if (loading) return <div className="p-8 text-muted-foreground">Loading...</div>;

  const hasPending = Object.values(pendingByProject).some((entries) => entries.length > 0);

  if (!hasPending) {
    return <div className="p-8"><h1 className="text-2xl font-bold mb-4">Approvals</h1><p className="text-muted-foreground">No pending entries. Everything is reviewed.</p></div>;
  }

  return (
    <div className="p-8 space-y-6">
      <h1 className="text-2xl font-bold">Approvals</h1>
      {projects.filter((p) => pendingByProject[p.id]?.length > 0).map((p) => (
        <Card key={p.id}>
          <CardHeader>
            <div className="flex items-center justify-between">
              <CardTitle>{p.name}</CardTitle>
              <div className="flex gap-2">
                <Button size="sm" onClick={() => approveAll(p.id)} disabled={!!actingProject[p.id]}>
                  {actingProject[p.id] === "approve" ? "Approving..." : "Approve All"}
                </Button>
                <Button size="sm" variant="destructive" onClick={() => rejectAll(p.id)} disabled={!!actingProject[p.id]}>
                  {actingProject[p.id] === "reject" ? "Rejecting..." : "Reject All"}
                </Button>
              </div>
            </div>
          </CardHeader>
          <CardContent className="space-y-3">
            {pendingByProject[p.id].map((e) => (
              <div key={e.id} className="border rounded-md p-3 space-y-2">
                <div className="flex items-center justify-between">
                  <button
                    className="font-medium text-left hover:underline cursor-pointer"
                    onClick={() => navigate(`/entries/${e.id}`)}
                  >
                    {e.title}
                  </button>
                  <Badge variant="secondary">{e.category}</Badge>
                </div>
                {e.tags.length > 0 && (
                  <div className="flex gap-1 flex-wrap">
                    {e.tags.map((t) => <Badge key={t} variant="outline">{t}</Badge>)}
                  </div>
                )}
                <div className="prose prose-sm dark:prose-invert max-w-none text-muted-foreground">
                  <ReactMarkdown>{e.content.slice(0, 300) + (e.content.length > 300 ? "..." : "")}</ReactMarkdown>
                </div>
                <div className="flex gap-2">
                  <Button size="sm" onClick={() => approveEntry(e.id, p.id)} disabled={!!actingIds[e.id]}>
                    {actingIds[e.id] === "approve" ? "Approving..." : "Approve"}
                  </Button>
                  <Button size="sm" variant="destructive" onClick={() => rejectEntry(e.id, p.id)} disabled={!!actingIds[e.id]}>
                    {actingIds[e.id] === "reject" ? "Rejecting..." : "Reject"}
                  </Button>
                </div>
              </div>
            ))}
          </CardContent>
        </Card>
      ))}
    </div>
  );
}
