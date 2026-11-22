import { useState } from "react";
import { Navigate, useLocation, useNavigate } from "react-router-dom";
import { useAuth } from "../components/AuthContext.jsx";
import { api } from "../api.js";

export default function Login() {
  const { user, login } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();

  const [role, setRole] = useState("chauffeur"); // "chauffeur" ou "admin"
  const [cin, setCin] = useState("");
  const [numeroPermis, setNumeroPermis] = useState("");
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [successMsg, setSuccessMsg] = useState("");
  const [loading, setLoading] = useState(false);

  const destination = location.state?.from || "/chauffeurs";
  if (user) return <Navigate to={destination} replace />;

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

        // Sauvegarde des identifiants chauffeur
        localStorage.setItem("chauffeur_token", data.access_token);
        localStorage.setItem("chauffeur_role", "CHAUFFEUR");
        setSuccessMsg("Connexion réussie ! Redirection vers l'Espace Chauffeur...");

        setTimeout(() => {
          window.location.href = "http://localhost:8002/espace-chauffeur";
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

    } else {
      // Connexion Administrateur
      if (!username.trim() || !password) {
        setError("Veuillez saisir votre nom d'utilisateur et votre mot de passe administrateur.");
        return;
      }

      setLoading(true);
      try {
        await login(username.trim(), password);
        setSuccessMsg("Connexion administrateur réussie ! Redirection...");
        setTimeout(() => {
          navigate("/chauffeurs", { replace: true });
        }, 300);
      } catch (err) {
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

  return (
    <div className="login-page">
      <form className="login-card" onSubmit={envoyer} noValidate>
        <div className="brand brand-dark">
          <span className="brand-mark" aria-hidden="true">F</span>
          <div>
            <strong>Gestion de Flotte</strong>
            <span>Portail unique d'authentification</span>
          </div>
        </div>

        <h1>Connexion</h1>
        <p className="muted">Choisissez votre rôle pour accéder à votre espace dédié.</p>

        {/* Sélecteur de rôle */}
        <div className="role-selector">
          <button
            type="button"
            className={`role-tab-btn ${role === "chauffeur" ? "active" : ""}`}
            onClick={() => handleRoleChange("chauffeur")}
          >
            <span>🚚</span> Chauffeur
          </button>
          <button
            type="button"
            className={`role-tab-btn ${role === "admin" ? "active" : ""}`}
            onClick={() => handleRoleChange("admin")}
          >
            <span>🛡️</span> Administrateur
          </button>
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
                placeholder="Ex. AB123456"
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
                placeholder="Ex. 12/34567"
                autoComplete="current-password"
              />
            </label>
          </>
        ) : (
          <>
            <label className="field">
              <span>Identifiant administrateur</span>
              <input
                value={username}
                onChange={(e) => setUsername(e.target.value)}
                placeholder="Ex. admin"
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
          {loading
            ? "Connexion en cours..."
            : role === "chauffeur"
            ? "Se connecter en tant que Chauffeur"
            : "Se connecter en tant qu'Administrateur"}
        </button>
      </form>
    </div>
  );
}
