import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Card, CardHeader, CardTitle, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { api, type Project, type Entry } from "@/lib/api";

export default function Dashboard() {
  const [projects, setProjects] = useState<Project[]>([]);
  const [recentEntries, setRecentEntries] = useState<Entry[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    async function load() {
      try {
        const projs = await api.listProjects();
        setProjects(projs);
        if (projs.length > 0) {
          const entries = await api.listEntries({ project_id: projs[0].id });
          setRecentEntries(entries.slice(0, 5));
        }
      } finally {
        setLoading(false);
      }
    }
    load();
  }, []);

  if (loading) return <div className="p-8 text-muted-foreground">Loading...</div>;

  const totalIndexed = projects.reduce((sum, p) => sum + (p.indexed_count ?? 0), 0);
  const totalPending = projects.reduce((sum, p) => sum + (p.pending_count ?? 0), 0);

  return (
    <div className="p-8 space-y-6">
      <h1 className="text-2xl font-bold">Dashboard</h1>
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <Card>
          <CardHeader><CardTitle className="text-sm">Projects</CardTitle></CardHeader>
          <CardContent><p className="text-3xl font-bold">{projects.length}</p></CardContent>
        </Card>
        <Card>
          <CardHeader><CardTitle className="text-sm">Indexed Entries</CardTitle></CardHeader>
          <CardContent><p className="text-3xl font-bold">{totalIndexed}</p></CardContent>
        </Card>
        <Card>
          <CardHeader><CardTitle className="text-sm">Pending</CardTitle></CardHeader>
          <CardContent><p className="text-3xl font-bold">{totalPending}</p></CardContent>
        </Card>
      </div>
      <div className="space-y-2">
        <h2 className="text-lg font-semibold">Recent Entries</h2>
        {recentEntries.length === 0 ? (
          <p className="text-muted-foreground">No entries yet.</p>
        ) : (
          <div className="space-y-2">
            {recentEntries.map((e) => (
              <Link key={e.id} to={`/entries/${e.id}`} className="block p-3 rounded-md border hover:bg-accent">
                <div className="flex items-center justify-between">
                  <span className="font-medium">{e.title}</span>
                  <Badge variant="secondary">{e.category}</Badge>
                </div>
                <p className="text-sm text-muted-foreground mt-1">{e.content.slice(0, 100)}...</p>
              </Link>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
