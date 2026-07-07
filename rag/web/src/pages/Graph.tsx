import { useEffect, useMemo, useRef, useState } from "react";
import { Link } from "react-router-dom";
import {
  forceSimulation,
  forceLink,
  forceManyBody,
  forceCenter,
  forceCollide,
  type SimulationNodeDatum,
} from "d3-force";
import { Select } from "@/components/ui/select";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { api, type Project, type Entity, type Relation, type EntityGraph } from "@/lib/api";

const WIDTH = 800;
const HEIGHT = 560;

interface GraphNode extends SimulationNodeDatum, Entity {}

interface GraphLink {
  source: GraphNode;
  target: GraphNode;
  predicate: string;
  id: number;
}

function nodeRadius(entryCount: number) {
  return 8 + Math.sqrt(Math.max(entryCount, 0)) * 4;
}

export default function Graph() {
  const [projects, setProjects] = useState<Project[]>([]);
  const [projectId, setProjectId] = useState("");
  const [entities, setEntities] = useState<Entity[]>([]);
  const [relations, setRelations] = useState<Relation[]>([]);
  const [loading, setLoading] = useState(true);
  const [hoveredLink, setHoveredLink] = useState<number | null>(null);
  const [selectedEntity, setSelectedEntity] = useState<Entity | null>(null);
  const [entityGraph, setEntityGraph] = useState<EntityGraph | null>(null);
  const [panelLoading, setPanelLoading] = useState(false);
  const latestEntityRequest = useRef(0);

  useEffect(() => {
    api.listProjects().then((projs) => {
      setProjects(projs);
      if (projs.length > 0) setProjectId(projs[0].id);
      else setLoading(false);
    });
  }, []);

  useEffect(() => {
    if (!projectId) return;
    let cancelled = false;
    latestEntityRequest.current++; // invalidate any in-flight node-click fetch
    setLoading(true);
    setSelectedEntity(null);
    setEntityGraph(null);
    setPanelLoading(false);
    api.getGraph(projectId).then((data) => {
      if (cancelled) return;
      setEntities(data.entities);
      setRelations(data.relations);
      setLoading(false);
    });
    return () => { cancelled = true; };
  }, [projectId]);

  const { nodes, links } = useMemo(() => {
    if (entities.length === 0) return { nodes: [] as GraphNode[], links: [] as GraphLink[] };
    const simNodes: GraphNode[] = entities.map((e) => ({ ...e }));
    const nodeById = new Map(simNodes.map((n) => [n.id, n]));
    const simLinks = relations
      .map((r) => {
        const source = nodeById.get(r.subject_id);
        const target = nodeById.get(r.object_id);
        if (!source || !target) return null;
        return { source, target, predicate: r.predicate, id: r.id };
      })
      .filter((l): l is GraphLink => l !== null);

    const simulation = forceSimulation(simNodes)
      .force(
        "link",
        forceLink<GraphNode, GraphLink>(simLinks)
          .id((d) => d.id)
          .distance(110)
          .strength(0.5)
      )
      .force("charge", forceManyBody().strength(-260))
      .force("center", forceCenter(WIDTH / 2, HEIGHT / 2))
      .force("collide", forceCollide<GraphNode>((d) => nodeRadius(d.entry_count) + 14))
      .stop();

    for (let i = 0; i < 300; i++) simulation.tick();

    return { nodes: simNodes, links: simLinks };
  }, [entities, relations]);

  async function handleNodeClick(entity: Entity) {
    const requestId = ++latestEntityRequest.current;
    setSelectedEntity(entity);
    setEntityGraph(null);
    setPanelLoading(true);
    try {
      const graph = await api.getEntityGraph(projectId, entity.name, 1);
      if (requestId !== latestEntityRequest.current) return;
      setEntityGraph(graph);
    } finally {
      if (requestId === latestEntityRequest.current) setPanelLoading(false);
    }
  }

  return (
    <div className="p-8 space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">Knowledge Graph</h1>
        <Select value={projectId} onChange={(e) => setProjectId(e.target.value)} className="w-56">
          {projects.map((p) => (
            <option key={p.id} value={p.id}>
              {p.name}
            </option>
          ))}
        </Select>
      </div>

      {loading ? (
        <p className="text-muted-foreground">Loading...</p>
      ) : entities.length === 0 ? (
        <p className="text-muted-foreground">
          No graph data yet for this project. Store knowledge with entities and relations to see them here.
        </p>
      ) : (
        <div className="flex gap-6">
          <div className="flex-1 border rounded-md bg-card overflow-hidden">
            <svg viewBox={`0 0 ${WIDTH} ${HEIGHT}`} className="w-full h-[560px]">
              <g>
                {links.map((l) => (
                  <g key={l.id} onMouseEnter={() => setHoveredLink(l.id)} onMouseLeave={() => setHoveredLink(null)}>
                    <line
                      x1={l.source.x ?? 0}
                      y1={l.source.y ?? 0}
                      x2={l.target.x ?? 0}
                      y2={l.target.y ?? 0}
                      stroke="currentColor"
                      className="text-muted-foreground"
                      strokeOpacity={hoveredLink === l.id ? 0.9 : 0.35}
                      strokeWidth={hoveredLink === l.id ? 2 : 1}
                    />
                    {hoveredLink === l.id && (
                      <text
                        x={((l.source.x ?? 0) + (l.target.x ?? 0)) / 2}
                        y={((l.source.y ?? 0) + (l.target.y ?? 0)) / 2}
                        textAnchor="middle"
                        className="fill-foreground text-[10px] font-medium"
                      >
                        {l.predicate}
                      </text>
                    )}
                  </g>
                ))}
              </g>
              <g>
                {nodes.map((n) => (
                  <g
                    key={n.id}
                    transform={`translate(${n.x ?? 0}, ${n.y ?? 0})`}
                    onClick={() => handleNodeClick(n)}
                    className="cursor-pointer"
                  >
                    <circle
                      r={nodeRadius(n.entry_count)}
                      className={
                        selectedEntity?.id === n.id
                          ? "fill-primary"
                          : "fill-secondary stroke-primary/40"
                      }
                      strokeWidth={1}
                    />
                    <text
                      y={nodeRadius(n.entry_count) + 12}
                      textAnchor="middle"
                      className="fill-foreground text-[11px]"
                    >
                      {n.name}
                    </text>
                  </g>
                ))}
              </g>
            </svg>
          </div>

          <div className="w-80 shrink-0">
            {!selectedEntity ? (
              <Card>
                <CardContent className="pt-4 text-sm text-muted-foreground">
                  Click a node to inspect its relations and linked entries.
                </CardContent>
              </Card>
            ) : (
              <Card>
                <CardContent className="pt-4 space-y-4">
                  <div className="flex items-center justify-between">
                    <h2 className="font-semibold">{selectedEntity.name}</h2>
                    <Button size="sm" variant="ghost" onClick={() => { setSelectedEntity(null); setEntityGraph(null); }}>
                      Close
                    </Button>
                  </div>
                  {selectedEntity.type && <Badge variant="outline">{selectedEntity.type}</Badge>}

                  {panelLoading ? (
                    <p className="text-sm text-muted-foreground">Loading...</p>
                  ) : entityGraph ? (
                    <>
                      <div className="space-y-2">
                        <h3 className="text-xs font-medium uppercase text-muted-foreground">Relations</h3>
                        {entityGraph.triples.length === 0 ? (
                          <p className="text-sm text-muted-foreground">No relations found.</p>
                        ) : (
                          <div className="flex flex-col gap-1.5">
                            {entityGraph.triples.map((t, i) => (
                              <div key={i} className="text-xs flex flex-wrap items-center gap-1">
                                <Badge variant="secondary">{t.subject}</Badge>
                                <span className="text-muted-foreground">— {t.predicate} →</span>
                                <Badge variant="secondary">{t.object}</Badge>
                              </div>
                            ))}
                          </div>
                        )}
                      </div>

                      <div className="space-y-2">
                        <h3 className="text-xs font-medium uppercase text-muted-foreground">Linked entries</h3>
                        {entityGraph.entries.length === 0 ? (
                          <p className="text-sm text-muted-foreground">No indexed entries found.</p>
                        ) : (
                          <div className="space-y-2">
                            {entityGraph.entries.map((e) => (
                              <Link
                                key={e.id}
                                to={`/entries/${e.id}`}
                                className="block p-2 rounded-md border hover:bg-accent text-sm"
                              >
                                <div className="flex items-center justify-between gap-2">
                                  <span className="font-medium truncate">{e.title}</span>
                                  <Badge variant="outline">{e.category}</Badge>
                                </div>
                              </Link>
                            ))}
                          </div>
                        )}
                      </div>
                    </>
                  ) : null}
                </CardContent>
              </Card>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
