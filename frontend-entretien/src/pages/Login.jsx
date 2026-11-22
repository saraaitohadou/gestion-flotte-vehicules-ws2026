import { useState } from "react";
import { Navigate, useNavigate } from "react-router-dom";
import { useAuth } from "../components/AuthContext.jsx";



const ONGLETS = [
  { id: "chauffeur", label: "Chauffeur" },
  { id: "gestionnaire", label: "Gestionnaire" },
  { id: "admin", label: "Admin" },
];

export default function Login() {
  const { user, login, loginGestionnaire, loginChauffeur } = useAuth();
  const navigate = useNavigate();

  const [onglet, setOnglet] = useState("chauffeur");
  const [cin, setCin] = useState("");
  const [permis, setPermis] = useState("");
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  if (user) return <Navigate to="/" replace />;

  const changerOnglet = (id) => { setOnglet(id); setError(""); };

  const envoyer = async (e) => {
    e.preventDefault();
    setError("");

    if (onglet === "chauffeur") {
      if (!cin.trim() || !permis.trim()) {
        setError("Saisissez votre CIN et votre numero de permis.");
        return;
      }
    } else if (!username.trim() || !password) {
      setError("Saisissez votre identifiant et votre mot de passe.");
      return;
    }

    setLoading(true);
    try {
      if (onglet === "chauffeur") await loginChauffeur(cin.trim().toUpperCase(), permis.trim());
      else if (onglet === "gestionnaire") await loginGestionnaire(username.trim(), password);
      else await login(username.trim(), password);
      navigate("/", { replace: true });
    } catch (err) {
      setError(err.message);
      setPassword("");
    } finally {
      setLoading(false);
    }
  };

  const libelleBouton = {
    chauffeur: "Se connecter en tant que Chauffeur",
    gestionnaire: "Se connecter en tant que Gestionnaire",
    admin: "Se connecter en tant qu'Administrateur",
  }[onglet];

  return (
    <div className="login-page">
      <form className="login-card" onSubmit={envoyer} noValidate>
        <div className="brand brand-dark">
          <span className="brand-mark" aria-hidden="true">F</span>
          <div>
            <strong>Gestion de Flotte</strong>
            <span>Module entretien</span>
          </div>
        </div>

        <h1>Connexion</h1>
        <p className="muted">Choisissez votre role pour acceder a votre espace.</p>

        <div className="role-selector role-selector-3">
          {ONGLETS.map((o) => (
            <button
              key={o.id}
              type="button"
              className={`role-tab-btn ${onglet === o.id ? "active" : ""}`}
              onClick={() => changerOnglet(o.id)}
            >
              {o.label}
            </button>
          ))}
        </div>

        {error && <p className="alert alert-bad" role="alert">{error}</p>}

        {onglet === "chauffeur" ? (
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
            </label>
            <label className="field">
              <span>Numero de permis</span>
              <input
                value={permis}
                onChange={(e) => setPermis(e.target.value)}
                placeholder="Ex. PERM-88412"
                autoComplete="current-password"
              />
            </label>
          </>
        ) : (
          <>
            <label className="field">
              <span>{onglet === "admin" ? "Identifiant administrateur" : "Identifiant gestionnaire"}</span>
              <input
                value={username}
                onChange={(e) => setUsername(e.target.value)}
                placeholder={onglet === "admin" ? "Ex. admin" : "Ex. gest1"}
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