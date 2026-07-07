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

  const [form, setForm] = useState({ id: "", name: "", description: "", language: "en" });
  const [paths, setPaths] = useState<string[]>([]);
  const [newPath, setNewPath] = useState("");

  async function load() {
    try {
      setProjects(await api.listProjects());
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(); }, []);

  async function handleCreate() {
    if (paths.length === 0) {
      alert("At least one path is required");
      return;
    }
    await api.createProject({
      id: form.id,
      name: form.name,
      paths,
      description: form.description,
      language: form.language,
    });
    resetForm();
    setShowCreate(false);
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

  function resetForm() {
    setForm({ id: "", name: "", description: "", language: "en" });
    setPaths([]);
    setNewPath("");
  }

  function openEdit(p: Project) {
    setEditProject(p);
    setForm({ id: p.id, name: p.name, description: p.description, language: p.language });
    setPaths(p.paths ?? (p.root_path ? [p.root_path] : []));
    setNewPath("");
  }

  async function addPath() {
    if (!newPath.trim()) return;
    const p = newPath.trim();
    if (paths.includes(p)) return;
    if (editProject) {
      try {
        await api.addProjectPath(editProject.id, p);
        setPaths([...paths, p]);
      } catch (e) { console.error(e); }
    } else {
      setPaths([...paths, p]);
    }
    setNewPath("");
  }

  async function removePath(path: string) {
    if (editProject) {
      // primary path (first) cannot be removed
      if (path === paths[0]) return;
      try {
        await api.removeProjectPath(editProject.id, path);
        setPaths(paths.filter((p) => p !== path));
      } catch (e) { console.error(e); }
    } else {
      setPaths(paths.filter((p) => p !== path));
    }
  }

  if (loading) return <div className="p-8 text-muted-foreground">Loading...</div>;

  return (
    <div className="p-8 space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">Projects</h1>
        <Button onClick={() => { resetForm(); setShowCreate(true); }}>
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
              <TableCell className="text-muted-foreground text-xs">
                {(p.paths ?? []).length > 0 ? (
                  <>
                    {p.paths![0]}
                    {(p.paths!.length > 1) && (
                      <Badge variant="secondary" className="ml-1">+{p.paths!.length - 1}</Badge>
                    )}
                  </>
                ) : p.root_path}
              </TableCell>
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
            <div className="space-y-2">
              <Label>Paths</Label>
              {paths.map((path, idx) => (
                <div key={path} className="flex items-center gap-2">
                  <span className="text-sm text-muted-foreground flex-1">{path}</span>
                  {idx === 0 ? (
                    <Badge variant="outline">primary</Badge>
                  ) : (
                    <Button size="sm" variant="ghost" className="text-destructive" onClick={() => removePath(path)}>Remove</Button>
                  )}
                </div>
              ))}
              <div className="flex gap-2">
                <Input
                  value={newPath}
                  onChange={(e) => setNewPath(e.target.value)}
                  placeholder={paths.length === 0 ? "/path/to/project (required)" : "/path/to/another/repo"}
                  onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); addPath(); } }}
                />
                <Button type="button" size="sm" onClick={addPath}>Add</Button>
              </div>
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
