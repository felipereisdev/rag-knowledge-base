import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import EntryForm from "@/components/EntryForm";
import { api, type Project } from "@/lib/api";

export default function NewEntry() {
  const navigate = useNavigate();
  const [projects, setProjects] = useState<Project[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.listProjects().then(setProjects).finally(() => setLoading(false));
  }, []);

  if (loading) return <div className="p-8 text-muted-foreground">Loading...</div>;
  if (projects.length === 0) return <div className="p-8 text-muted-foreground">Create a project first.</div>;

  return (
    <div className="p-8">
      <h1 className="text-2xl font-bold mb-6">New Entry</h1>
      <EntryForm
        projects={projects}
        onSubmit={() => navigate("/entries")}
        onCancel={() => navigate("/entries")}
      />
    </div>
  );
}
