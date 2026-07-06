import { useEffect, useState } from "react";
import { useParams, useNavigate } from "react-router-dom";
import ReactMarkdown from "react-markdown";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Dialog, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog";
import EntryForm from "@/components/EntryForm";
import { api, type Entry, type Project } from "@/lib/api";

export default function EntryDetail() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [entry, setEntry] = useState<Entry | null>(null);
  const [projects, setProjects] = useState<Project[]>([]);
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
