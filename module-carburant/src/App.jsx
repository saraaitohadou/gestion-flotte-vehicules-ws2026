import { useState } from "react";
import { Navigate, Route, Routes } from "react-router-dom";
import Sidebar from "./components/Sidebar";
import Header from "./components/Header";
import Dashboard from "./pages/Dashboard";
import Fillups from "./pages/Fillups";
import FillupForm from "./pages/FillupForm";
import Alerts from "./pages/Alerts";
import Stats from "./pages/Stats";
import RequireAuth from "./components/RequireAuth";
import { useAuth } from "./components/AuthContext";

function RequireWriteAccess({ children }) {
  const { user } = useAuth();
  return user?.role === "CHAUFFEUR" ? <Navigate to="/pleins" replace /> : children;
}

export default function App() {
  const [menuOpen, setMenuOpen] = useState(false);

  return (
    <Routes>
      <Route element={<RequireAuth />}>
        <Route path="*" element={
          <div className="app-shell">
            <Sidebar open={menuOpen} onClose={() => setMenuOpen(false)} />
            <div className="main-area">
              <Header onMenu={() => setMenuOpen((value) => !value)} />
              <Routes>
                <Route path="/" element={<Dashboard />} />
                <Route path="/pleins" element={<Fillups />} />
                <Route path="/nouveau" element={<RequireWriteAccess><FillupForm /></RequireWriteAccess>} />
                <Route path="/modifier/:id" element={<RequireWriteAccess><FillupForm /></RequireWriteAccess>} />
                <Route path="/alertes" element={<Alerts />} />
                <Route path="/stats" element={<Stats />} />
                <Route path="*" element={<Navigate to="/" replace />} />
              </Routes>
            </div>
          </div>
        } />
      </Route>
    </Routes>
  );
}
