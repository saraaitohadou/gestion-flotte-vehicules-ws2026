import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { api } from "../api.js";
import { formatDate, formatKm } from "../utils.js";

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

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Alertes</h1>
          <p className="muted">Echeances proches ou depassees (15 jours / 500 km) et entretiens planifies.</p>
        </div>
      </div>
      {error && <p className="alert alert-bad" role="alert">{error}</p>}
      <div className="panel" style={{ overflowX: "auto" }}>
        {loading ? <p className="muted">Chargement...</p> : rows.length === 0 ? <p className="muted">Aucune alerte ouverte.</p> : (
          <table style={{ width: "100%", borderCollapse: "collapse" }}>
            <thead>
              <tr style={{ textAlign: "left", color: "var(--muted)", fontSize: "0.85rem" }}>
                <th style={{ padding: 8 }}>Vehicule</th>
                <th>Categorie</th>
                <th>Type</th>
                <th>Message</th>
                <th>Echeance</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {rows.map((a) => (
                <tr key={a.id} style={{ borderTop: "1px solid var(--line)" }}>
                  <td style={{ padding: 8 }}>#{a.vehicule_id}</td>
                  <td>{a.categorie ?? "-"}</td>
                  <td>
                    <span className={`badge badge-${a.type === "EN_RETARD" ? "bad" : "warn"}`}>
                      {a.type}
                    </span>
                  </td>
                  <td>{a.message}</td>
                  <td>
                    {formatDate(a.echeance_date)}
                    {a.echeance_km != null && ` · ${formatKm(a.echeance_km)}`}
                  </td>
                  <td className="right"><Link to={`/entretien/${a.entretien_id}`}>Entretien</Link></td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </>
  );
}
