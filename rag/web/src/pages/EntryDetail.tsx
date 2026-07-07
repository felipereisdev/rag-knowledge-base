import { useEffect, useState } from "react";
import { useParams, useNavigate } from "react-router-dom";
import ReactMarkdown from "react-markdown";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Dialog, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog";
import EntryForm from "@/components/EntryForm";
import { api, type Entry, type Project, type EntryGraph } from "@/lib/api";

export default function EntryDetail() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [entry, setEntry] = useState<Entry | null>(null);
  const [projects, setProjects] = useState<Project[]>([]);
  const [entryGraph, setEntryGraph] = useState<EntryGraph | null>(null);
  const [editing, setEditing] = useState(false);
  const [showDelete, setShowDelete] = useState(false);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    async function load() {
      if (!id) return;
      try {
        const [e, projs] = await Promise.all([api.getEntry(id), api.listProjects()]);
        setEntry(e);
        setProjects(projs);
      } finally {
        setLoading(false);
      }
    }
    load();
    if (id) {
      // Fetched separately: a graph endpoint failure must not break the entry page.
      api.getEntryGraph(id).then(setEntryGraph).catch(() => setEntryGraph(null));
    }
  }, [id]);

  async function handleApprove() {
    if (!entry) return;
    await api.approveEntry(entry.id);
    setEntry(await api.getEntry(entry.id));
  }

  async function handleReject() {
    if (!entry) return;
    await api.rejectEntry(entry.id);
    setEntry(await api.getEntry(entry.id));
  }

  async function handleDelete() {
    if (!entry) return;
    await api.deleteEntry(entry.id);
    navigate("/entries");
  }

  if (loading) return <div className="p-8 text-muted-foreground">Loading...</div>;
  if (!entry) return <div className="p-8 text-muted-foreground">Entry not found.</div>;

  if (editing) {
    return (
      <div className="p-8">
        <h1 className="text-2xl font-bold mb-6">Edit Entry</h1>
        <EntryForm
          projects={projects}
          entry={entry}
          onSubmit={async () => { setEntry(await api.getEntry(entry.id)); setEditing(false); }}
          onCancel={() => setEditing(false)}
        />
      </div>
    );
  }

  return (
    <div className="p-8 space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">{entry.title}</h1>
        <div className="flex gap-2">
          <Button variant="outline" onClick={() => setEditing(true)}>Edit</Button>
          <Button variant="destructive" onClick={() => setShowDelete(true)}>Delete</Button>
        </div>
      </div>

      <div className="flex gap-2 flex-wrap">
        <Badge variant="secondary">{entry.category}</Badge>
        <Badge variant={entry.status === "indexed" ? "default" : entry.status === "pending" ? "outline" : "destructive"}>
          {entry.status}
        </Badge>
        {entry.tags.map((t) => <Badge key={t} variant="outline">{t}</Badge>)}
      </div>

      <Card>
        <CardContent>
          <div className="prose prose-sm dark:prose-invert max-w-none mt-4">
            <ReactMarkdown>{entry.content}</ReactMarkdown>
          </div>
        </CardContent>
      </Card>

      {entryGraph && (entryGraph.entities.length > 0 || entryGraph.relations.length > 0) && (
        <Card>
          <CardContent className="pt-4 space-y-3">
            <h2 className="text-sm font-semibold">Knowledge graph</h2>
            {entryGraph.entities.length > 0 && (
              <div className="flex gap-2 flex-wrap">
                {entryGraph.entities.map((e) => (
                  <Badge key={e.id} variant="secondary">
                    {e.name}
                    {e.type && <span className="ml-1 opacity-70">({e.type})</span>}
                  </Badge>
                ))}
              </div>
            )}
            {entryGraph.relations.length > 0 && (
              <div className="flex flex-col gap-1.5">
                {entryGraph.relations.map((r) => (
                  <div key={r.id} className="text-xs flex flex-wrap items-center gap-1">
                    <Badge variant="outline">{r.subject}</Badge>
                    <span className="text-muted-foreground">— {r.predicate} →</span>
                    <Badge variant="outline">{r.object}</Badge>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      )}

      {entry.status === "pending" && (
        <div className="flex gap-2">
          <Button onClick={handleApprove}>Approve</Button>
          <Button variant="destructive" onClick={handleReject}>Reject</Button>
        </div>
      )}

      <div className="text-xs text-muted-foreground">
        <p>Source: {entry.source}</p>
        <p>Created: {new Date(entry.created_at * 1000).toLocaleString()}</p>
        <p>Updated: {new Date(entry.updated_at * 1000).toLocaleString()}</p>
      </div>

      {showDelete && (
        <Dialog open onOpenChange={setShowDelete}>
          <DialogHeader><DialogTitle>Delete Entry</DialogTitle></DialogHeader>
          <p className="text-sm text-muted-foreground">Are you sure you want to delete "{entry.title}"?</p>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowDelete(false)}>Cancel</Button>
            <Button variant="destructive" onClick={handleDelete}>Delete</Button>
          </DialogFooter>
        </Dialog>
      )}
    </div>
  );
}
