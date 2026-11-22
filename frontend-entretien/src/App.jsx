import { Routes, Route, Navigate } from "react-router-dom";
import Layout from "./components/Layout.jsx";
import RequireAuth from "./components/RequireAuth.jsx";
import RequireRole from "./components/RequireRole.jsx";
import Login from "./pages/Login.jsx";
import Home from "./pages/Home.jsx";
import Placeholder from "./pages/Placeholder.jsx";
import EntretienDetail from "./pages/EntretienDetail.jsx";
import DeclarerPanne from "./pages/DeclarerPanne.jsx";
import DeclarationsList from "./pages/DeclarationsList.jsx";
import Alertes from "./pages/Alertes.jsx";
import Gestionnaires from "./pages/Gestionnaires.jsx";
import EntretiensList from "./pages/EntretiensList.jsx";
import EntretienForm from "./pages/EntretienForm.jsx";
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
            <Route path="/entretien/liste" element={<EntretiensList />} />
            <Route path="/entretien/nouveau" element={<EntretienForm />} />
            <Route path="/entretien/:id" element={<EntretienDetail />} />
            <Route path="/entretien/:id/modifier" element={<EntretienForm />} />
            <Route path="/entretien/declarations" element={<DeclarationsList gestion />} />
            <Route path="/entretien/alertes" element={<Alertes />} />
          </Route>

          <Route element={<RequireRole roles={[ROLES.ADMIN]} />}>
            <Route path="/entretien/gestionnaires" element={<Gestionnaires />} />
          </Route>

          <Route element={<RequireRole roles={DECLARANTS} />}>
            <Route path="/pannes/nouvelle" element={<DeclarerPanne />} />
          </Route>

          <Route element={<RequireRole roles={[ROLES.CHAUF]} />}>
            <Route path="/pannes/mes-declarations" element={<DeclarationsList />} />
          </Route>
        </Route>
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}