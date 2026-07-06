import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { api, type Project, type SearchResult } from "@/lib/api";

const CATEGORIES = ["", "business-rule", "design-decision", "architecture", "documentation", "insight", "convention", "constraint"];

export default function Search() {
  const [projects, setProjects] = useState<Project[]>([]);
  const [projectId, setProjectId] = useState("");
  const [query, setQuery] = useState("");
  const [category, setCategory] = useState("");
  const [results, setResults] = useState<SearchResult[]>([]);
  const [searching, setSearching] = useState(false);

  useEffect(() => {
    api.listProjects().then((projs) => {
      setProjects(projs);
      if (projs.length > 0) setProjectId(projs[0].id);
    });
  }, []);

  useEffect(() => {
    if (!query.trim() || !projectId) {
      setResults([]);
      return;
    }
    const timer = setTimeout(async () => {
      setSearching(true);
      try {
        const r = await api.search({ q: query, project_id: projectId, category: category || undefined });
        setResults(r);
      } finally {
        setSearching(false);
      }
    }, 300);
    return () => clearTimeout(timer);
  }, [query, projectId, category]);

  return (
    <div className="p-8 space-y-6 max-w-3xl">
      <h1 className="text-2xl font-bold">Search</h1>
      <Input
        placeholder="Search the knowledge base..."
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        className="text-lg"
      />
      <div className="flex gap-4">
        <Select value={projectId} onChange={(e) => setProjectId(e.target.value)} className="w-48">
          {projects.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
        </Select>
        <Select value={category} onChange={(e) => setCategory(e.target.value)} className="w-48">
          {CATEGORIES.map((c) => <option key={c} value={c}>{c || "All categories"}</option>)}
        </Select>
      </div>

      {searching && <p className="text-muted-foreground">Searching...</p>}

      {results.length > 0 && (
        <div className="space-y-3">
          {results.map((r) => (
            <Card key={r.id}>
              <CardContent className="pt-4">
                <Link to={`/entries/${r.id}`} className="block">
                  <div className="flex items-center justify-between mb-2">
                    <span className="font-medium hover:underline">{r.title}</span>
                    <div className="flex gap-2">
                      <Badge variant="secondary">{r.category}</Badge>
                      <Badge variant="outline">score: {r.score}</Badge>
                    </div>
                  </div>
                  <p className="text-sm text-muted-foreground">{r.content.slice(0, 200)}...</p>
                  {r.tags.length > 0 && (
                    <div className="flex gap-1 mt-2">
                      {r.tags.map((t) => <Badge key={t} variant="outline">{t}</Badge>)}
                    </div>
                  )}
                </Link>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      {!searching && query.trim() && results.length === 0 && projectId && (
        <p className="text-muted-foreground">No results found.</p>
      )}
    </div>
  );
}
