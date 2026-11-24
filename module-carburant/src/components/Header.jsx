import { Menu, Plus } from "lucide-react";
import { Link, useLocation } from "react-router-dom";
import { useAuth } from "./AuthContext";

const titles = {
  "/": "Tableau de bord",
  "/pleins": "Pleins",
  "/nouveau": "Nouveau plein",
  "/alertes": "Alertes",
  "/stats": "Statistiques",
};

export default function Header({ onMenu }) {
  const { pathname } = useLocation();
  const { user } = useAuth();
  const chauffeur = user?.role === "CHAUFFEUR";
  return (
    <header className="topbar">
      <div className="title-row">
        <button className="menu-button" type="button" onClick={onMenu} aria-label="Ouvrir le menu"><Menu size={22} /></button>
        <h1>{titles[pathname] || "Tableau de bord"}</h1>
      </div>
      <div className="header-actions">
        {!chauffeur && pathname !== "/nouveau" && <Link className="primary-button header-primary" to="/nouveau"><Plus size={17} /> Nouveau plein</Link>}
        <a className="docs-link" href="/docs" target="_blank" rel="noopener noreferrer">Documentation de l’API</a>
      </div>
    </header>
  );
}
