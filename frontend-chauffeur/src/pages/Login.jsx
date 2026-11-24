import { useState } from "react";
import { Navigate, useLocation } from "react-router-dom";
import { useAuth } from "../components/AuthContext.jsx";
import { getToken, setToken } from "../auth.js";
import { api } from "../api.js";

const PORTAIL_ADMIN_URL = "http://localhost:5170";
const ENTRETIEN_FRONT_URL = "http://localhost:5176";

const ONGLETS = [
  { id: "chauffeur", icon: "🚚", label: "Chauffeur" },
  { id: "gestionnaire", icon: "🔧", label: "Gestionnaire" },
  { id: "admin", icon: "🛡️", label: "Administrateur" },
];

export default function Login() {
  const { user, login } = useAuth();
  const location = useLocation();

  const [role, setRole] = useState("chauffeur"); // "chauffeur" | "gestionnaire" | "admin"
  const [cin, setCin] = useState("");
  const [numeroPermis, setNumeroPermis] = useState("");
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [successMsg, setSuccessMsg] = useState("");
  const [loading, setLoading] = useState(false);
  const [redirecting, setRedirecting] = useState(false);

  const destination = location.state?.from || "/chauffeurs";
  if (user && !redirecting) return <Navigate to={destination} replace />;

  const handleRoleChange = (newRole) => {
    setRole(newRole);
    setError("");
    setSuccessMsg("");
  };

  const envoyer = async (e) => {
    e.preventDefault();
    setError("");
    setSuccessMsg("");

    if (role === "chauffeur") {
      if (!cin.trim() || !numeroPermis.trim()) {
        setError("Veuillez saisir à la fois votre CIN et votre numéro de permis.");
        return;
      }

      setLoading(true);
      try {
        const data = await api.loginChauffeur(cin.trim().toUpperCase(), numeroPermis.trim());
        if (!data || !data.access_token) {
          throw new Error("Réponse d'authentification invalide.");
        }

        localStorage.setItem("chauffeur_token", data.access_token);
        localStorage.setItem("chauffeur_role", "CHAUFFEUR");
        setSuccessMsg("Connexion réussie ! Redirection vers l'Espace Chauffeur...");

        setTimeout(() => {
          window.location.href = `http://localhost:8002/espace-chauffeur#token=${encodeURIComponent(data.access_token)}`;
        }, 500);
      } catch (err) {
        if (err.status === 403) {
          setError("Accès refusé : votre compte chauffeur est inactif. Veuillez contacter l'administrateur.");
        } else if (err.status === 401) {
          setError("Identifiants invalides : CIN ou numéro de permis incorrect.");
        } else {
          setError(err.message || "Erreur réseau : impossible de joindre le service Chauffeur.");
        }
      } finally {
        setLoading(false);
      }

    } else if (role === "gestionnaire") {
      if (!username.trim() || !password) {
        setError("Veuillez saisir votre identifiant et votre mot de passe gestionnaire.");
        return;
      }

      setLoading(true);
      setRedirecting(true);
      try {
        const data = await api.loginGestionnaire(username.trim(), password);
        if (!data || !data.access_token) {
          throw new Error("Réponse d'authentification invalide.");
        }
        setToken(data.access_token);
        setSuccessMsg("Connexion gestionnaire réussie ! Redirection vers Maintenance & Entretien...");
        setTimeout(() => {
          window.location.href = `${ENTRETIEN_FRONT_URL}?token=${encodeURIComponent(data.access_token)}`;
        }, 300);
      } catch (err) {
        setRedirecting(false);
        setError(err.message || "Identifiant ou mot de passe gestionnaire incorrect.");
        setPassword("");
      } finally {
        setLoading(false);
      }

    } else {
      // Connexion Administrateur -> Portail Global (4 services)
      if (!username.trim() || !password) {
        setError("Veuillez saisir votre nom d'utilisateur et votre mot de passe administrateur.");
        return;
      }

      setLoading(true);
      setRedirecting(true);
      try {
        await login(username.trim(), password);
        const token = getToken();
        setSuccessMsg("Connexion administrateur réussie ! Redirection vers le Portail Global...");
        setTimeout(() => {
          window.location.href = `${PORTAIL_ADMIN_URL}?token=${encodeURIComponent(token)}`;
        }, 300);
      } catch (err) {
        setRedirecting(false);
        if (err.status === 401) {
          setError("Identifiant ou mot de passe administrateur incorrect.");
        } else if (err.status === 403) {
          setError("Accès refusé : ce compte ne dispose pas des privilèges administrateur.");
        } else {
          setError(err.message || "Erreur réseau : impossible de joindre le service d'authentification.");
        }
        setPassword("");
      } finally {
        setLoading(false);
      }
    }
  };

  const libelleBouton = {
    chauffeur: "Se connecter en tant que Chauffeur",
    gestionnaire: "Se connecter en tant que Gestionnaire",
    admin: "Se connecter en tant qu'Administrateur",
  }[role];

  return (
    <div className="login-page">
      <form className="login-card" onSubmit={envoyer} noValidate>
        <div className="brand brand-dark">
          <span className="brand-mark" aria-hidden="true">F</span>
          <div>
            <strong>Gestion de Flotte</strong>
            <span>Portail unique d&apos;authentification</span>
          </div>
        </div>

        <h1>Connexion</h1>
        <p className="muted">Choisissez votre rôle pour accéder à votre espace dédié.</p>

        {/* Sélecteur des 3 rôles */}
        <div className="role-selector" style={{ display: "grid", gridTemplateColumns: "repeat(3, 1fr)", gap: "6px" }}>
          {ONGLETS.map((o) => (
            <button
              key={o.id}
              type="button"
              className={`role-tab-btn ${role === o.id ? "active" : ""}`}
              onClick={() => handleRoleChange(o.id)}
              style={{ fontSize: "0.85rem", padding: "8px 6px", justifyContent: "center" }}
            >
              <span>{o.icon}</span> {o.label}
            </button>
          ))}
        </div>

        {error && <p className="alert alert-bad" role="alert">{error}</p>}
        {successMsg && <p className="alert alert-ok" role="alert">{successMsg}</p>}

        {role === "chauffeur" ? (
          <>
            <label className="field">
              <span>CIN Conducteur</span>
              <input
                value={cin}
                onChange={(e) => setCin(e.target.value)}
                placeholder="Ex. J123456"
                autoComplete="username"
                style={{ textTransform: "uppercase" }}
                autoFocus
              />
              <small className="muted">1 ou 2 lettres suivies de 5 ou 6 chiffres</small>
            </label>
            <label className="field">
              <span>Numéro de permis</span>
              <input
                value={numeroPermis}
                onChange={(e) => setNumeroPermis(e.target.value)}
                placeholder="Ex. PERM-88412"
                autoComplete="current-password"
              />
            </label>
          </>
        ) : (
          <>
            <label className="field">
              <span>
                {role === "admin" ? "Identifiant administrateur" : "Identifiant gestionnaire"}
              </span>
              <input
                value={username}
                onChange={(e) => setUsername(e.target.value)}
                placeholder={role === "admin" ? "Ex. admin" : "Ex. gest1"}
                autoComplete="username"
                autoFocus
              />
            </label>
            <label className="field">
              <span>Mot de passe</span>
              <input
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="••••••••"
                autoComplete="current-password"
              />
            </label>
          </>
        )}

        <button className="btn btn-primary btn-block" disabled={loading}>
          {loading ? "Connexion en cours..." : libelleBouton}
        </button>
      </form>
    </div>
  );
}
