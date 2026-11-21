import { useCallback, useEffect, useState } from "react";
import { api } from "../api.js";
import { useAuth } from "../components/AuthContext.jsx";
import { useToast } from "../components/Toast.jsx";
import { ROLES } from "../roles.js";

const TONES = { DECLAREE: "warn", TRAITEE: "ok", REJETEE: "mute" };
const LABELS = { DECLAREE: "Declaree", TRAITEE: "Traitee", REJETEE: "Rejetee" };

function DeclBadge({ statut }) {
  return <span className={`badge badge-${TONES[statut] ?? "mute"}`}>{LABELS[statut] ?? statut}</span>;
}

// gestion=true : staff (boutons) ; sinon "Mes declarations" du chauffeur
export default function DeclarationsList({ gestion }) {
  const { user } = useAuth();
  const toast = useToast();
  const [rows, setRows] = useState([]);
  const [filtre, setFiltre] = useState(gestion ? "DECLAREE" : "");
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(null);
  const [error, setError] = useState("");

  const charger = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const res = await api.declarations({ statut: filtre });
      let data = res.data ?? [];
      if (!gestion && user?.role === ROLES.CHAUF) {
        data = data.filter((d) => String(d.chauffeur_id) === String(user.username));
      }
      setRows(data);
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }, [filtre, gestion, user]);

  useEffect(() => { charger(); }, [charger]);

  const agir = async (d, fn, msg) => {
    setBusy(d.id);
    setError("");
    try {
      await fn(d.id);
      toast(msg);
      await charger();
    } catch (e) {
      setError(e.code ? `${e.message} (${e.code})` : e.message);
    } finally {
      setBusy(null);
    }
  };

  return (
    <>
      <div className="page-head">
        <div>
          <h1>{gestion ? "Declarations de panne" : "Mes declarations"}</h1>
          <p className="muted">{rows.length} declaration(s)</p>
        </div>
      </div>

      <div className="panel" style={{ display: "flex", gap: 12 }}>
        <label className="field" style={{ margin: 0 }}>
          <span>Statut</span>
          <select value={filtre} onChange={(e) => setFiltre(e.target.value)}>
            <option value="">Tous</option>
            {Object.entries(LABELS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </label>
      </div>

      {error && <p className="alert alert-bad" role="alert">{error}</p>}

      <div className="panel" style={{ overflowX: "auto" }}>
        {loading ? <p className="muted">Chargement...</p> : rows.length === 0 ? <p className="muted">Aucune declaration.</p> : (
          <table style={{ width: "100%", borderCollapse: "collapse" }}>
            <thead>
              <tr style={{ textAlign: "left", color: "var(--muted)", fontSize: "0.85rem" }}>
                <th style={{ padding: 8 }}>#</th><th>Vehicule</th><th>Chauffeur</th><th>Categorie</th><th>Description</th><th>Km</th><th>Statut</th>{gestion && <th></th>}
              </tr>
            </thead>
            <tbody>
              {rows.map((d) => (
                <tr key={d.id} style={{ borderTop: "1px solid var(--line)" }}>
                  <td style={{ padding: 8 }}>{d.id}</td>
                  <td>#{d.vehicule_id}</td>
                  <td>#{d.chauffeur_id}</td>
                  <td>{d.categorie}</td>
                  <td>{d.description}</td>
                  <td>{d.kilometrage ?? "-"}</td>
                  <td><DeclBadge statut={d.statut} /></td>
                  {gestion && (
                    <td className="right" style={{ whiteSpace: "nowrap" }}>
                      {d.statut === "DECLAREE" && (
                        <>
                          <button className="btn btn-primary" disabled={busy === d.id} onClick={() => agir(d, (i) => api.prendreEnCharge(i), "Declaration prise en charge.")}>Prendre en charge</button>{" "}
                          <button className="btn" disabled={busy === d.id} onClick={() => agir(d, (i) => api.rejeter(i), "Declaration rejetee.")}>Rejeter</button>
                        </>
                      )}
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </>
  );
}