import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Card, CardHeader, CardTitle, CardContent } from "@/components/ui/card";
import { api, type Project, type Entry } from "@/lib/api";

export default function Approvals() {
  const [projects, setProjects] = useState<Project[]>([]);
  const [pendingByProject, setPendingByProject] = useState<Record<string, Entry[]>>({});
  const [loading, setLoading] = useState(true);

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
    await api.approveEntry(id);
    const updated = pendingByProject[projectId].filter((e) => e.id !== id);
    setPendingByProject({ ...pendingByProject, [projectId]: updated });
  }

  async function rejectEntry(id: string, projectId: string) {
    await api.rejectEntry(id);
    const updated = pendingByProject[projectId].filter((e) => e.id !== id);
    setPendingByProject({ ...pendingByProject, [projectId]: updated });
  }

  async function approveAll(projectId: string) {
    await api.approveAll(projectId);
    setPendingByProject({ ...pendingByProject, [projectId]: [] });
  }

  async function rejectAll(projectId: string) {
    await api.rejectAll(projectId);
    setPendingByProject({ ...pendingByProject, [projectId]: [] });
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
                <Button size="sm" onClick={() => approveAll(p.id)}>Approve All</Button>
                <Button size="sm" variant="destructive" onClick={() => rejectAll(p.id)}>Reject All</Button>
              </div>
            </div>
          </CardHeader>
          <CardContent className="space-y-3">
            {pendingByProject[p.id].map((e) => (
              <div key={e.id} className="border rounded-md p-3 space-y-2">
                <div className="flex items-center justify-between">
                  <span className="font-medium">{e.title}</span>
                  <Badge variant="secondary">{e.category}</Badge>
                </div>
                {e.tags.length > 0 && (
                  <div className="flex gap-1 flex-wrap">
                    {e.tags.map((t) => <Badge key={t} variant="outline">{t}</Badge>)}
                  </div>
                )}
                <pre className="text-sm text-muted-foreground whitespace-pre-wrap">{e.content.slice(0, 300)}{e.content.length > 300 ? "..." : ""}</pre>
                <div className="flex gap-2">
                  <Button size="sm" onClick={() => approveEntry(e.id, p.id)}>Approve</Button>
                  <Button size="sm" variant="destructive" onClick={() => rejectEntry(e.id, p.id)}>Reject</Button>
                </div>
              </div>
            ))}
          </CardContent>
        </Card>
      ))}
    </div>
  );
}
