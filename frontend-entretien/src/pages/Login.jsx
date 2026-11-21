import { useState } from "react";
import { Navigate, useNavigate } from "react-router-dom";
import { useAuth } from "../components/AuthContext.jsx";
import { makeDevToken } from "../devToken.js";
import { ROLES } from "../roles.js";

export default function Login() {
  const { user, login, loginWithToken } = useAuth();
  const navigate = useNavigate();
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  // mode dev
  const [devRole, setDevRole] = useState(ROLES.GEST);
  const [devSub, setDevSub] = useState("7");

  if (user) return <Navigate to="/" replace />;

  const envoyer = async (e) => {
    e.preventDefault();
    if (!username.trim() || !password) {
      setError("Saisissez votre identifiant et votre mot de passe.");
      return;
    }
    setError("");
    setLoading(true);
    try {
      await login(username, password);
      navigate("/", { replace: true });
    } catch (err) {
      setError(err.message);
      setPassword("");
    } finally {
      setLoading(false);
    }
  };

  const entrerDev = async () => {
    loginWithToken(await makeDevToken(devRole, devSub));
    navigate("/", { replace: true });
  };

  return (
    <div className="login-page">
      <form className="login-card" onSubmit={envoyer} noValidate>
        <div className="brand brand-dark">
          <span className="brand-mark" aria-hidden="true">F</span>
          <div>
            <strong>Flotte</strong>
            <span>Module entretien</span>
          </div>
        </div>

        <h1>Connexion</h1>
        <p className="muted">Connectez-vous pour gerer les entretiens.</p>

        {error && <p className="alert alert-bad" role="alert">{error}</p>}

        <label className="field">
          <span>Identifiant</span>
          <input value={username} onChange={(e) => setUsername(e.target.value)} autoComplete="username" autoFocus />
        </label>
        <label className="field">
          <span>Mot de passe</span>
          <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" />
        </label>

        <button className="btn btn-primary btn-block" disabled={loading}>
          {loading ? "Connexion..." : "Se connecter"}
        </button>

        {import.meta.env.DEV && (
          <div style={{ marginTop: 24, paddingTop: 16, borderTop: "1px dashed var(--line)" }}>
            <h2>Mode dev (test des roles)</h2>
            <p className="muted small">Genere un token local. Visible uniquement en developpement.</p>
            <label className="field">
              <span>Role</span>
              <select value={devRole} onChange={(e) => {
                setDevRole(e.target.value);
                setDevSub(e.target.value === ROLES.CHAUF ? "2" : "7");
              }}>
                <option value={ROLES.ADMIN}>ADMINISTRATEUR</option>
                <option value={ROLES.GEST}>GESTIONNAIRE</option>
                <option value={ROLES.CHAUF}>CHAUFFEUR</option>
              </select>
            </label>
            <label className="field">
              <span>Identifiant (sub)</span>
              <input value={devSub} onChange={(e) => setDevSub(e.target.value)} />
            </label>
            <button type="button" className="btn btn-block" onClick={entrerDev}>Entrer en mode dev</button>
          </div>
        )}
      </form>
    </div>
  );
}