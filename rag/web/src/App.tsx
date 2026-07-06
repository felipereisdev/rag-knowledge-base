import { Routes, Route } from "react-router-dom";
import Layout from "@/components/Layout";
import Dashboard from "@/pages/Dashboard";
import Projects from "@/pages/Projects";
import Entries from "@/pages/Entries";
import EntryDetail from "@/pages/EntryDetail";
import NewEntry from "@/pages/NewEntry";
import Approvals from "@/pages/Approvals";
import Search from "@/pages/Search";

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<Layout />}>
        <Route index element={<Dashboard />} />
        <Route path="projects" element={<Projects />} />
        <Route path="entries" element={<Entries />} />
        <Route path="entries/new" element={<NewEntry />} />
        <Route path="entries/:id" element={<EntryDetail />} />
        <Route path="approvals" element={<Approvals />} />
        <Route path="search" element={<Search />} />
      </Route>
    </Routes>
  );
}
