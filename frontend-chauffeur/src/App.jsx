import { Routes, Route, Navigate } from "react-router-dom";
import Layout from "./components/Layout.jsx";
import RequireAuth from "./components/RequireAuth.jsx";
import Login from "./pages/Login.jsx";
import Dashboard from "./pages/Dashboard.jsx";
import ChauffeursList from "./pages/ChauffeursList.jsx";
import ChauffeurForm from "./pages/ChauffeurForm.jsx";
import ChauffeurProfile from "./pages/ChauffeurProfile.jsx";

export default function App() {
  return (
    <Routes>
      <Route path="/connexion" element={<Login />} />
      <Route element={<RequireAuth />}>
        <Route element={<Layout />}>
          <Route path="/" element={<Dashboard />} />
          <Route path="/chauffeurs" element={<ChauffeursList />} />
          <Route path="/chauffeurs/nouveau" element={<ChauffeurForm />} />
          <Route path="/chauffeurs/:id" element={<ChauffeurProfile />} />
          <Route path="/chauffeurs/:id/modifier" element={<ChauffeurForm />} />
        </Route>
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
