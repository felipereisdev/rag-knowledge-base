import { Routes, Route } from "react-router-dom";
import Layout from "@/components/Layout";
import Dashboard from "@/pages/Dashboard";
import Projects from "@/pages/Projects";

function Placeholder({ name }: { name: string }) {
  return <div className="p-8 text-muted-foreground">{name} — coming soon</div>;
}

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<Layout />}>
        <Route index element={<Dashboard />} />
        <Route path="projects" element={<Projects />} />
        <Route path="entries" element={<Placeholder name="Entries" />} />
        <Route path="entries/new" element={<Placeholder name="New Entry" />} />
        <Route path="entries/:id" element={<Placeholder name="Entry Detail" />} />
        <Route path="approvals" element={<Placeholder name="Approvals" />} />
        <Route path="search" element={<Placeholder name="Search" />} />
      </Route>
    </Routes>
  );
}
