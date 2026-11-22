import { useCallback, useEffect, useState } from "react";
import { api } from "../api.js";
import { useToast } from "../components/Toast.jsx";

const dt = (iso) => (iso ? new Date(iso).toLocaleString("fr-FR") : "-");

export default function Gestionnaires() {
  const toast = useToast();
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [form, setForm] = useState({ username: "", password: "" });
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [reset, setReset] = useState(null); // { id, password }

  const charger = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const res = await api.gestionnaires();
      setRows(res.data ?? []);
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { charger(); }, [charger]);

  const creer = async (ev) => {
    ev.preventDefault();
    setError("");
    const e = {};
    if (form.username.trim().length < 3) e.username = "3 caracteres minimum.";
    if (form.password.length < 8) e.password = "8 caracteres minimum.";
    setErrors(e);
    if (Object.keys(e).length) return;

    setSaving(true);
    try {
      await api.creerGestionnaire({ username: form.username.trim(), password: form.password });
      toast("Gestionnaire cree.");
      setForm({ username: "", password: "" });
      await charger();
    } catch (err) {
      if (err.fields) setErrors(err.fields);
      setError(err.message);
    } finally {
      setSaving(false);
    }
  };

  const basculer = async (g) => {
    setError("");
    try {
      await api.basculerGestionnaire(g.id, !g.actif);
      toast(g.actif ? "Compte desactive." : "Compte active.");
      await charger();
    } catch (err) {
      setError(err.message);
    }
  };

  const changerMdp = async () => {
    if (!reset || reset.password.length < 8) {
      setError("Le mot de passe doit contenir 8 caracteres minimum.");
      return;
    }
    setError("");
    try {
      await api.motDePasseGestionnaire(reset.id, reset.password);
      toast("Mot de passe modifie.");
      setReset(null);
    } catch (err) {
      setError(err.message);
    }
  };

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Gestionnaires</h1>
          <p className="muted">Comptes autorises a gerer les entretiens.</p>
        </div>
      </div>

      {error && <p className="alert alert-bad" role="alert">{error}</p>}

      <form className="panel" onSubmit={creer} noValidate>
        <h2>Nouveau gestionnaire</h2>
        <fieldset disabled={saving} style={{ border: 0, padding: 0, margin: 0 }}>
          <div className="grid-2">
            <label className="field">
              <span>Identifiant</span>
              <input value={form.username} onChange={(e) => setForm({ ...form, username: e.target.value })} autoComplete="off" />
              {errors.username && <span className="error small">{errors.username}</span>}
            </label>
            <label className="field">
              <span>Mot de passe</span>
              <input type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} autoComplete="new-password" />
              {errors.password && <span className="error small">{errors.password}</span>}
            </label>
          </div>
          <button className="btn btn-primary" style={{ marginTop: 12 }}>{saving ? "Creation..." : "Creer le compte"}</button>
        </fieldset>
      </form>

      <div className="table-wrap">
        {loading ? <p className="muted" style={{ padding: 20 }}>Chargement...</p> : (
          <table className="table">
            <thead>
              <tr><th>#</th><th>Identifiant</th><th>Statut</th><th>Cree par</th><th>Cree le</th><th className="right">Actions</th></tr>
            </thead>
            <tbody>
              {rows.length === 0 && <tr><td className="empty" colSpan="6">Aucun gestionnaire.</td></tr>}
              {rows.map((g) => (
                <tr key={g.id}>
                  <td>{g.id}</td>
                  <td><strong>{g.username}</strong></td>
                  <td><span className={`badge badge-${g.actif ? "ok" : "mute"}`}>{g.actif ? "Actif" : "Desactive"}</span></td>
                  <td>{g.cree_par ?? "-"}</td>
                  <td>{dt(g.created_at)}</td>
                  <td className="right">
                    {reset?.id === g.id ? (
                      <span className="actions" style={{ justifyContent: "flex-end" }}>
                        <input
                          type="password"
                          placeholder="Nouveau mot de passe"
                          style={{ width: 190 }}
                          value={reset.password}
                          onChange={(e) => setReset({ id: g.id, password: e.target.value })}
                        />
                        <button type="button" className="btn btn-sm btn-primary" onClick={changerMdp}>OK</button>
                        <button type="button" className="btn btn-sm" onClick={() => setReset(null)}>Annuler</button>
                      </span>
                    ) : (
                      <span className="actions" style={{ justifyContent: "flex-end" }}>
                        <button type="button" className="btn btn-sm" onClick={() => setReset({ id: g.id, password: "" })}>Mot de passe</button>
                        <button type="button" className={`btn btn-sm ${g.actif ? "btn-danger-ghost" : ""}`} onClick={() => basculer(g)}>
                          {g.actif ? "Desactiver" : "Activer"}
                        </button>
                      </span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </>
  );
}