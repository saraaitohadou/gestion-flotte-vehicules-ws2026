import { NavLink, Outlet, useNavigate } from "react-router-dom";
import { useAuth } from "./AuthContext.jsx";
import { useToast } from "./Toast.jsx";

export default function Layout() {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const toast = useToast();

  const deconnecter = () => {
    logout();
    toast("Vous êtes déconnecté.");
    navigate("/connexion", { replace: true });
  };

  return (
    <div className="shell">
      <aside className="sidebar">
        <div className="brand">
          <span className="brand-mark" aria-hidden="true">F</span>
          <div>
            <strong>Flotte</strong>
            <span>Module chauffeurs</span>
          </div>
        </div>
        <nav>
          <NavLink to="/dashboard" end>Tableau de bord</NavLink>
          <NavLink to="/chauffeurs" end>Liste des chauffeurs</NavLink>
          <NavLink to="/chauffeurs/nouveau">Ajouter un chauffeur</NavLink>
        </nav>

        <div className="sidebar-foot">
          <div className="sidebar-user">
            <span className="avatar">{user?.username?.slice(0, 2).toUpperCase()}</span>
            <div>
              <strong>{user?.username}</strong>
              <span>Administrateur</span>
            </div>
          </div>
          <button className="btn btn-logout" onClick={deconnecter}>Se déconnecter</button>
        </div>
      </aside>
      <main className="content">
        <Outlet />
      </main>
    </div>
  );
}
