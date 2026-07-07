import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import ReactMarkdown from "react-markdown";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { api, type Project, type SearchResult, type SearchGraph } from "@/lib/api";

const CATEGORIES = ["", "business-rule", "design-decision", "architecture", "documentation", "insight", "convention", "constraint"];

const EMPTY_GRAPH: SearchGraph = { triples: [], related_entries: [] };

export default function Search() {
  const [projects, setProjects] = useState<Project[]>([]);
  const [projectId, setProjectId] = useState("all");
  const [query, setQuery] = useState("");
  const [category, setCategory] = useState("");
  const [results, setResults] = useState<SearchResult[]>([]);
  const [graph, setGraph] = useState<SearchGraph>(EMPTY_GRAPH);
  const [searching, setSearching] = useState(false);

  useEffect(() => {
    api.listProjects().then(setProjects);
  }, []);

  useEffect(() => {
    if (!query.trim()) {
      setResults([]);
      setGraph(EMPTY_GRAPH);
      return;
    }
    let cancelled = false;
    const timer = setTimeout(async () => {
      setSearching(true);
      try {
        const r = await api.search({
          q: query,
          project_id: projectId === "all" ? undefined : projectId,
          category: category || undefined,
          expand: true,
        });
        if (cancelled) return;
        setResults(r.results);
        setGraph(r.graph);
      } finally {
        if (!cancelled) setSearching(false);
      }
    }, 300);
    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
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
          <option value="all">All projects</option>
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
                  <div className="prose prose-sm dark:prose-invert max-w-none text-muted-foreground">
                    <ReactMarkdown>{r.content.slice(0, 200) + "..."}</ReactMarkdown>
                  </div>
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

      {!searching && (graph.triples.length > 0 || graph.related_entries.length > 0) && (
        <div className="space-y-3">
          <h2 className="text-lg font-semibold">Knowledge graph</h2>
          {graph.triples.length > 0 && (
            <div className="flex flex-wrap gap-2">
              {graph.triples.map((t, i) => (
                <div key={i} className="text-xs flex items-center gap-1 rounded-full border px-2.5 py-1">
                  <Badge variant="secondary">{t.subject}</Badge>
                  <span className="text-muted-foreground">— {t.predicate} →</span>
                  <Badge variant="secondary">{t.object}</Badge>
                </div>
              ))}
            </div>
          )}
          {graph.related_entries.length > 0 && (
            <div className="space-y-3">
              {graph.related_entries.map((e) => (
                <Card key={e.id}>
                  <CardContent className="pt-4">
                    <Link to={`/entries/${e.id}`} className="block">
                      <div className="flex items-center justify-between mb-2">
                        <span className="font-medium hover:underline">{e.title}</span>
                        <Badge variant="secondary">{e.category}</Badge>
                      </div>
                      <div className="prose prose-sm dark:prose-invert max-w-none text-muted-foreground">
                        <ReactMarkdown>{e.content.slice(0, 200) + "..."}</ReactMarkdown>
                      </div>
                      {e.tags.length > 0 && (
                        <div className="flex gap-1 mt-2">
                          {e.tags.map((t) => <Badge key={t} variant="outline">{t}</Badge>)}
                        </div>
                      )}
                    </Link>
                  </CardContent>
                </Card>
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
