import { useEffect, useState } from "react";
import { Fuel, LayoutDashboard, List, PlusCircle, TriangleAlert, BarChart3, X, LogOut } from "lucide-react";
import { NavLink } from "react-router-dom";
import { getAlerts } from "../services/api";
import { useAuth } from "./AuthContext";

const links = [
  { to: "/", label: "Tableau de bord", icon: LayoutDashboard },
  { to: "/pleins", label: "Pleins", icon: List },
  { to: "/alertes", label: "Alertes", icon: TriangleAlert },
  { to: "/stats", label: "Statistiques", icon: BarChart3 },
];

export default function Sidebar({ open, onClose }) {
  const [alertCount, setAlertCount] = useState(0);
  const { user, logout } = useAuth();

  const handleLogout = () => {
    logout();
    onClose();
    const authUrl = import.meta.env.VITE_AUTH_URL || "http://localhost:5174/connexion?logout=1";
    window.location.assign(authUrl);
  };

  useEffect(() => {
    getAlerts().then((alerts) => setAlertCount(alerts.length)).catch(() => setAlertCount(0));
  }, []);
  const visibleLinks = user?.role === "CHAUFFEUR"
    ? links.filter((link) => link.to !== "/nouveau")
    : links;
  return (
    <>
      {open && <button className="sidebar-backdrop" type="button" aria-label="Fermer le menu" onClick={onClose} />}
      <aside className={`sidebar ${open ? "sidebar-open" : ""}`}>
        <div className="brand-row">
          <div className="brand">
            <span className="brand-icon"><Fuel size={22} /></span>
            <span>Flotte · Carburant</span>
          </div>
          <button className="sidebar-close" type="button" onClick={onClose} aria-label="Fermer le menu"><X size={20} /></button>
        </div>
        <nav className="nav-list" aria-label="Navigation principale">
          {visibleLinks.map(({ to, label, icon: Icon }) => (
            <NavLink key={to} to={to} end={to === "/"} onClick={onClose} className={({ isActive }) => `nav-item ${isActive ? "active" : ""}`}>
              <Icon size={18} />
              <span>{label}</span>
              {to === "/alertes" && alertCount > 0 && <span className="nav-badge" aria-label={`${alertCount} alertes`}>{alertCount}</span>}
            </NavLink>
          ))}
        </nav>
        <div className="sidebar-footer">
          <div className="sidebar-user">
            <span className="sidebar-user-avatar">{(user?.username || "U").charAt(0).toUpperCase()}</span>
            <div>
              <strong>{user?.username || "Utilisateur"}</strong>
              <span>{user?.role === "CHAUFFEUR" ? "Chauffeur" : "Administrateur"}</span>
            </div>
          </div>
          <button className="logout-button" type="button" onClick={handleLogout}>
            <LogOut size={18} />
            <span>Se déconnecter</span>
          </button>
        </div>
      </aside>
    </>
  );
}
