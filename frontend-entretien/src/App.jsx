import { Routes, Route, Navigate } from "react-router-dom";
import Layout from "./components/Layout.jsx";
import RequireAuth from "./components/RequireAuth.jsx";
import RequireRole from "./components/RequireRole.jsx";
import Login from "./pages/Login.jsx";
import Home from "./pages/Home.jsx";
import Placeholder from "./pages/Placeholder.jsx";
import { STAFF, DECLARANTS, ROLES } from "./roles.js";

export default function App() {
  return (
    <Routes>
      <Route path="/connexion" element={<Login />} />
      <Route element={<RequireAuth />}>
        <Route element={<Layout />}>
          <Route path="/" element={<Home />} />

          <Route element={<RequireRole roles={STAFF} />}>
            <Route path="/entretien" element={<Placeholder titre="Tableau de bord entretien" />} />
            <Route path="/entretien/liste" element={<Placeholder titre="Entretiens" />} />
            <Route path="/entretien/nouveau" element={<Placeholder titre="Nouvel entretien" />} />
            <Route path="/entretien/:id" element={<Placeholder titre="Detail entretien" />} />
            <Route path="/entretien/:id/modifier" element={<Placeholder titre="Modifier l'entretien" />} />
            <Route path="/entretien/declarations" element={<Placeholder titre="Declarations de panne" />} />
            <Route path="/entretien/alertes" element={<Placeholder titre="Alertes" />} />
          </Route>

          <Route element={<RequireRole roles={DECLARANTS} />}>
            <Route path="/pannes/nouvelle" element={<Placeholder titre="Declarer une panne" />} />
          </Route>

          <Route element={<RequireRole roles={[ROLES.CHAUF]} />}>
            <Route path="/pannes/mes-declarations" element={<Placeholder titre="Mes declarations" />} />
          </Route>
        </Route>
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}