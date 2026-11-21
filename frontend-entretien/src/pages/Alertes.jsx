import { useEffect, useState } from "react";
import { api } from "../api.js";

export default function Alertes() {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    (async () => {
      try {
        const res = await api.alertes();
        setRows(res.data ?? []);
      } catch (e) {
        setError(e.message);
      } finally {
        setLoading(false);
      }
    })();
  }, []);

  const cols = rows.length ? Object.keys(rows[0]).filter((k) => typeof rows[0][k] !== "object") : [];

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Alertes</h1>
          <p className="muted">Echeances proches ou depassees (15 jours / 500 km).</p>
        </div>
      </div>
      {error && <p className="alert alert-bad" role="alert">{error}</p>}
      <div className="panel" style={{ overflowX: "auto" }}>
        {loading ? <p className="muted">Chargement...</p> : rows.length === 0 ? <p className="muted">Aucune alerte ouverte.</p> : (
          <table style={{ width: "100%", borderCollapse: "collapse" }}>
            <thead>
              <tr style={{ textAlign: "left", color: "var(--muted)", fontSize: "0.85rem" }}>
                {cols.map((k) => <th key={k} style={{ padding: 8 }}>{k}</th>)}
              </tr>
            </thead>
            <tbody>
              {rows.map((a, i) => (
                <tr key={a.id ?? i} style={{ borderTop: "1px solid var(--line)" }}>
                  {cols.map((k) => (
                    <td key={k} style={{ padding: 8 }}>
                      {["statut", "niveau", "type"].includes(k)
                        ? <span className={`badge badge-${String(a[k]).includes("RETARD") ? "bad" : "warn"}`}>{String(a[k])}</span>
                        : String(a[k] ?? "-")}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </>
  );
}