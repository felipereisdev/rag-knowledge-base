import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { api, type Project, type EntryCreate, type EntryUpdate } from "@/lib/api";

const CATEGORIES = [
  "business-rule", "design-decision", "architecture",
  "documentation", "insight", "convention", "constraint",
];

interface EntryFormProps {
  projects: Project[];
  entry?: { id: string; title: string; content: string; category: string; tags: string[]; project_id: string };
  onSubmit: () => void;
  onCancel: () => void;
}

export default function EntryForm({ projects, entry, onSubmit, onCancel }: EntryFormProps) {
  const [title, setTitle] = useState(entry?.title ?? "");
  const [content, setContent] = useState(entry?.content ?? "");
  const [category, setCategory] = useState(entry?.category ?? "insight");
  const [tags, setTags] = useState((entry?.tags ?? []).join(", "));
  const [projectId, setProjectId] = useState(entry?.project_id ?? projects[0]?.id ?? "");
  const [error, setError] = useState("");

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError("");
    const tagList = tags.split(",").map((t) => t.trim().toLowerCase()).filter(Boolean);
    try {
      if (entry) {
        const update: EntryUpdate = { title, content, category, tags: tagList };
        await api.updateEntry(entry.id, update);
      } else {
        const data: EntryCreate = { project_id: projectId, title, content, category, tags: tagList };
        await api.createEntry(data);
      }
      onSubmit();
    } catch (err) {
      setError(String((err as Error)?.message || err));
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4 max-w-2xl">
      {!entry && (
        <div className="space-y-2">
          <Label htmlFor="project">Project</Label>
          <Select id="project" value={projectId} onChange={(e) => setProjectId(e.target.value)} required>
            {projects.map((p) => (
              <option key={p.id} value={p.id}>{p.name}</option>
            ))}
          </Select>
        </div>
      )}
      <div className="space-y-2">
        <Label htmlFor="title">Title</Label>
        <Input id="title" value={title} onChange={(e) => setTitle(e.target.value)} required />
      </div>
      <div className="space-y-2">
        <Label htmlFor="content">Content</Label>
        <Textarea id="content" value={content} onChange={(e) => setContent(e.target.value)} rows={8} required />
      </div>
      <div className="space-y-2">
        <Label htmlFor="category">Category</Label>
        <Select id="category" value={category} onChange={(e) => setCategory(e.target.value)}>
          {CATEGORIES.map((c) => (
            <option key={c} value={c}>{c}</option>
          ))}
        </Select>
      </div>
      <div className="space-y-2">
        <Label htmlFor="tags">Tags (comma-separated)</Label>
        <Input id="tags" value={tags} onChange={(e) => setTags(e.target.value)} placeholder="auth, security, payments" />
      </div>
      {error && <p className="text-sm text-destructive">{error}</p>}
      <div className="flex gap-2">
        <Button type="submit">{entry ? "Update" : "Create"}</Button>
        <Button type="button" variant="outline" onClick={onCancel}>Cancel</Button>
      </div>
    </form>
  );
}
