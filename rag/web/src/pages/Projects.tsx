import { useEffect, useState } from "react";
import { Card, CardHeader, CardTitle, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Badge } from "@/components/ui/badge";
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from "@/components/ui/table";
import { Dialog, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog";
import { api, type Project } from "@/lib/api";

export default function Projects() {
  const [projects, setProjects] = useState<Project[]>([]);
  const [loading, setLoading] = useState(true);
  const [showCreate, setShowCreate] = useState(false);
  const [editProject, setEditProject] = useState<Project | null>(null);
  const [deleteProject, setDeleteProject] = useState<Project | null>(null);

  const [form, setForm] = useState({ id: "", name: "", root_path: "", description: "", language: "en" });

  async function load() {
    try {
      setProjects(await api.listProjects());
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(); }, []);

  async function handleCreate() {
    await api.createProject(form);
    setShowCreate(false);
    setForm({ id: "", name: "", root_path: "", description: "", language: "en" });
    await load();
  }

  async function handleUpdate() {
    if (!editProject) return;
    await api.updateProject(editProject.id, {
      name: form.name,
      description: form.description,
      language: form.language,
    });
    setEditProject(null);
    await load();
  }

  async function handleDelete() {
    if (!deleteProject) return;
    await api.deleteProject(deleteProject.id);
    setDeleteProject(null);
    await load();
  }

  function openEdit(p: Project) {
    setEditProject(p);
    setForm({ id: p.id, name: p.name, root_path: p.root_path, description: p.description, language: p.language });
  }

  if (loading) return <div className="p-8 text-muted-foreground">Loading...</div>;

  return (
    <div className="p-8 space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">Projects</h1>
        <Button onClick={() => { setForm({ id: "", name: "", root_path: "", description: "", language: "en" }); setShowCreate(true); }}>
          New Project
        </Button>
      </div>

      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Name</TableHead>
            <TableHead>Path</TableHead>
            <TableHead>Language</TableHead>
            <TableHead>Indexed</TableHead>
            <TableHead>Pending</TableHead>
            <TableHead>Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {projects.map((p) => (
            <TableRow key={p.id}>
              <TableCell className="font-medium">{p.name}</TableCell>
              <TableCell className="text-muted-foreground text-xs">{p.root_path}</TableCell>
              <TableCell><Badge variant="outline">{p.language}</Badge></TableCell>
              <TableCell>{p.indexed_count}</TableCell>
              <TableCell>{p.pending_count}</TableCell>
              <TableCell>
                <div className="flex gap-2">
                  <Button size="sm" variant="ghost" onClick={() => openEdit(p)}>Edit</Button>
                  <Button size="sm" variant="ghost" className="text-destructive" onClick={() => setDeleteProject(p)}>Delete</Button>
                </div>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>

      {(showCreate || editProject) && (
        <Dialog open onOpenChange={() => { setShowCreate(false); setEditProject(null); }}>
          <DialogHeader><DialogTitle>{editProject ? "Edit Project" : "New Project"}</DialogTitle></DialogHeader>
          <div className="space-y-4">
            {!editProject && (
              <div className="space-y-2">
                <Label>Project ID</Label>
                <Input value={form.id} onChange={(e) => setForm({ ...form, id: e.target.value })} required />
              </div>
            )}
            <div className="space-y-2">
              <Label>Name</Label>
              <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
            </div>
            {!editProject && (
              <div className="space-y-2">
                <Label>Root Path</Label>
                <Input value={form.root_path} onChange={(e) => setForm({ ...form, root_path: e.target.value })} required />
              </div>
            )}
            <div className="space-y-2">
              <Label>Description</Label>
              <Input value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
            </div>
            <div className="space-y-2">
              <Label>Language</Label>
              <Select value={form.language} onChange={(e) => setForm({ ...form, language: e.target.value })}>
                <option value="en">English</option>
                <option value="pt-BR">Português (BR)</option>
                <option value="es">Español</option>
                <option value="fr">Français</option>
              </Select>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => { setShowCreate(false); setEditProject(null); }}>Cancel</Button>
            <Button onClick={editProject ? handleUpdate : handleCreate}>{editProject ? "Update" : "Create"}</Button>
          </DialogFooter>
        </Dialog>
      )}

      {deleteProject && (
        <Dialog open onOpenChange={() => setDeleteProject(null)}>
          <DialogHeader><DialogTitle>Delete Project</DialogTitle></DialogHeader>
          <p className="text-sm text-muted-foreground">
            Are you sure you want to delete "{deleteProject.name}"? This will remove all its entries.
          </p>
          <DialogFooter>
            <Button variant="outline" onClick={() => setDeleteProject(null)}>Cancel</Button>
            <Button variant="destructive" onClick={handleDelete}>Delete</Button>
          </DialogFooter>
        </Dialog>
      )}
    </div>
  );
}
