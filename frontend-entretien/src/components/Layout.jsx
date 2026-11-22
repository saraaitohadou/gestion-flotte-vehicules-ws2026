import { NavLink, Outlet, useNavigate } from "react-router-dom";
import { useAuth } from "./AuthContext.jsx";
import { useToast } from "./Toast.jsx";
import { LIBELLES, ROLES, STAFF } from "../roles.js";

export default function Layout() {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const toast = useToast();
  const role = user?.role;

  const deconnecter = () => {
    logout();
    toast("Vous etes deconnecte.");
    navigate("/connexion", { replace: true });
  };

  return (
    <div className="shell">
      <aside className="sidebar">
        <div className="brand">
          <span className="brand-mark" aria-hidden="true">F</span>
          <div>
            <strong>Flotte</strong>
            <span>Module entretien</span>
          </div>
        </div>

        <nav>
          {STAFF.includes(role) && (
            <>
              <NavLink to="/entretien" end>Tableau de bord</NavLink>
              <NavLink to="/entretien/liste">Entretiens</NavLink>
              <NavLink to="/entretien/declarations">Declarations de panne</NavLink>
              <NavLink to="/entretien/alertes">Alertes</NavLink>
            </>
          )}
          {role === ROLES.ADMIN && (<><NavLink to="/entretien/gestionnaires">Gestionnaires</NavLink><NavLink to="/pannes/nouvelle">Declarer une panne</NavLink></>)}
          {role === ROLES.CHAUF && (
            <>
              <NavLink to="/pannes/nouvelle">Declarer une panne</NavLink>
              <NavLink to="/pannes/mes-declarations">Mes declarations</NavLink>
            </>
          )}
        </nav>

        <div className="sidebar-foot">
          <div className="sidebar-user">
            <span className="avatar">{String(user?.username ?? "?").slice(0, 2).toUpperCase()}</span>
            <div>
              <strong>Utilisateur {user?.username}</strong>
              <span>{LIBELLES[role] ?? "Role inconnu"}</span>
            </div>
          </div>
          <button className="btn btn-logout" onClick={deconnecter}>Se deconnecter</button>
        </div>
      </aside>
      <main className="content">
        <Outlet />
      </main>
    </div>
  );
}