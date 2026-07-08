<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Knowledge Graph Explorer</title>
    <style>
        body { font-family: sans-serif; margin: 0; padding: 0; background: #f9fafb; }
        .toolbar { display: flex; gap: 12px; padding: 16px; background: white; border-bottom: 1px solid #e5e7eb; align-items: center; }
        .toolbar select, .toolbar input { border: 1px solid #d1d5db; border-radius: 4px; padding: 6px 10px; font-size: 14px; }
        .toolbar input { flex: 1; }
        #graph-canvas { height: calc(100vh - 120px); border: 1px solid #ccc; margin: 16px; border-radius: 4px; background: white; }
        #graph-info { padding: 8px 16px; font-size: 14px; color: #6b7280; }
        h1 { font-size: 20px; margin: 0; padding: 16px 16px 0; }
    </style>
</head>
<body>
    <h1>Knowledge Graph Explorer</h1>
    <div class="toolbar">
        <select id="project-filter">
            <option value="">All projects</option>
        </select>
        <input id="entity-search" type="text" placeholder="Search entity...">
    </div>
    <div id="graph-canvas"></div>
    <div id="graph-info"></div>

    <script src="https://unpkg.com/vis-network/standalone/umd/vis-network.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', async () => {
        const canvas = document.getElementById('graph-canvas');
        const info = document.getElementById('graph-info');
        const projectFilter = document.getElementById('project-filter');
        const search = document.getElementById('entity-search');
        let network = null;

        async function loadGraph(projectId) {
            const url = projectId
                ? `/martis/graph/data?project_id=${encodeURIComponent(projectId)}`
                : '/martis/graph/data';
            const res = await fetch(url);
            const data = await res.json();

            const nodes = new vis.DataSet(data.nodes.map(n => ({
                id: n.id,
                label: n.label,
                title: n.type || '',
            })));
            const edges = new vis.DataSet(data.edges.map(e => ({
                from: e.from,
                to: e.to,
                label: e.label,
                arrows: 'to',
            })));

            if (network) network.destroy();
            network = new vis.Network(canvas, { nodes, edges }, {
                layout: { improvedLayout: true },
                physics: { stabilization: true },
                edges: { font: { size: 10 } },
            });

            network.on('selectNode', (params) => {
                const node = nodes.get(params.nodes[0]);
                info.textContent = `Selected: ${node.label}`;
            });
        }

        async function loadProjects() {
            const res = await fetch('/martis/graph/data');
            const data = await res.json();
            const seen = new Set();
            data.nodes.forEach(n => {
                if (n.project_id && !seen.has(n.project_id)) {
                    seen.add(n.project_id);
                    const opt = document.createElement('option');
                    opt.value = n.project_id;
                    opt.textContent = n.project_id;
                    projectFilter.appendChild(opt);
                }
            });
        }

        projectFilter.addEventListener('change', () => loadGraph(projectFilter.value));
        loadProjects();
        loadGraph();
    });
    </script>
</body>
</html>