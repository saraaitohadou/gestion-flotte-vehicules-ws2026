import { Navigate } from "react-router-dom";
import { useAuth } from "../components/AuthContext.jsx";
import { homeFor } from "../roles.js";

// "/" : envoie chaque role vers sa page d'accueil
export default function Home() {
  const { user, logout } = useAuth();
  const dest = homeFor(user?.role);
  if (dest) return <Navigate to={dest} replace />;
  return (
    <div className="panel">
      <h2>Acces refuse</h2>
      <p>Votre role ({user?.role ?? "inconnu"}) n'a pas acces au module Entretien.</p>
      <button className="btn" onClick={logout}>Se deconnecter</button>
    </div>
  );
}