import { useState } from "react";
import { Navigate, useLocation, useNavigate } from "react-router-dom";
import { useAuth } from "../components/AuthContext.jsx";

export default function Login() {
  const { user, login } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  const destination = location.state?.from || "/";
  if (user) return <Navigate to={destination} replace />;

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
      navigate(destination, { replace: true });
    } catch (err) {
      setError(err.message);
      setPassword("");
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="login-page">
      <form className="login-card" onSubmit={envoyer} noValidate>
        <div className="brand brand-dark">
          <span className="brand-mark" aria-hidden="true">F</span>
          <div>
            <strong>Flotte</strong>
            <span>Espace administrateur</span>
          </div>
        </div>

        <h1>Connexion</h1>
        <p className="muted">Connectez-vous pour gérer les chauffeurs.</p>

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
      </form>
    </div>
  );
}
