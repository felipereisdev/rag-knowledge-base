import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Select } from "@/components/ui/select";
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from "@/components/ui/table";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { api, type Project, type Entry } from "@/lib/api";

const CATEGORIES = ["", "business-rule", "design-decision", "architecture", "documentation", "insight", "convention", "constraint"];

export default function Entries() {
  const [projects, setProjects] = useState<Project[]>([]);
  const [entries, setEntries] = useState<Entry[]>([]);
  const [loading, setLoading] = useState(true);
  const [projectId, setProjectId] = useState("all");
  const [category, setCategory] = useState("");
  const [status, setStatus] = useState("indexed");

  useEffect(() => {
    async function load() {
      const projs = await api.listProjects();
      setProjects(projs);
    }
    load();
  }, []);

  useEffect(() => {
    setLoading(true);
    api.listEntries({ project_id: projectId === "all" ? undefined : projectId, category: category || undefined, status: status === "all" ? undefined : status })
      .then(setEntries)
      .finally(() => setLoading(false));
  }, [projectId, category, status]);

  return (
    <div className="p-8 space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">Entries</h1>
        <Link to="/entries/new"><Button>New Entry</Button></Link>
      </div>

      <div className="flex gap-4 items-center">
        <Select value={projectId} onChange={(e) => setProjectId(e.target.value)} className="w-48">
          <option value="all">All projects</option>
          {projects.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
        </Select>
        <Select value={category} onChange={(e) => setCategory(e.target.value)} className="w-48">
          {CATEGORIES.map((c) => <option key={c} value={c}>{c || "All categories"}</option>)}
        </Select>
        <Tabs value={status} onValueChange={setStatus}>
          <TabsList>
            <TabsTrigger value="all">All</TabsTrigger>
            <TabsTrigger value="indexed">Indexed</TabsTrigger>
            <TabsTrigger value="pending">Pending</TabsTrigger>
            <TabsTrigger value="rejected">Rejected</TabsTrigger>
          </TabsList>
        </Tabs>
      </div>

      {loading ? (
        <p className="text-muted-foreground">Loading...</p>
      ) : entries.length === 0 ? (
        <p className="text-muted-foreground">No entries found.</p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Title</TableHead>
              <TableHead>Project</TableHead>
              <TableHead>Category</TableHead>
              <TableHead>Tags</TableHead>
              <TableHead>Status</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {entries.map((e) => (
              <TableRow key={e.id}>
                <TableCell>
                  <Link to={`/entries/${e.id}`} className="font-medium hover:underline">{e.title}</Link>
                </TableCell>
                <TableCell className="text-xs text-muted-foreground">
                  {projects.find((p) => p.id === e.project_id)?.name ?? e.project_id}
                </TableCell>
                <TableCell><Badge variant="secondary">{e.category}</Badge></TableCell>
                <TableCell className="text-xs text-muted-foreground">{e.tags.join(", ")}</TableCell>
                <TableCell>
                  <Badge variant={e.status === "indexed" ? "default" : e.status === "pending" ? "outline" : "destructive"}>
                    {e.status}
                  </Badge>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
    </div>
  );
}
