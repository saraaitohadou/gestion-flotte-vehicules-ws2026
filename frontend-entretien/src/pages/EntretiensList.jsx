import { useCallback, useEffect, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { api, ARCHIVES } from "../api.js";
import { StatusBadge, TypeBadge } from "../components/Badges.jsx";
import { STATUTS, TYPES, formatDate, formatKm, formatMoney } from "../utils.js";

export default function EntretiensList() {
  const [params, setParams] = useSearchParams();
  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const statut = params.get("statut") ?? "";
  const type = params.get("type") ?? "";
  const vehicule = params.get("vehicule_id") ?? "";
  const archivesParam = params.get("archives");
  const archives = Object.values(ARCHIVES).includes(archivesParam) ? archivesParam : ARCHIVES.MASQUER;
  const page = Number(params.get("page") ?? 1);

  const charger = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const res = await api.list({
        statut,
        type,
        vehicule_id: vehicule,
        archives: archives === ARCHIVES.MASQUER ? "" : archives,
        page,
      });
      setRows(res.data ?? []);
      setMeta(res.meta ?? null);
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }, [statut, type, vehicule, archives, page]);

  useEffect(() => { charger(); }, [charger]);

  const setFiltre = (key, value) => {
    const next = new URLSearchParams(params);
    if (value) next.set(key, value); else next.delete(key);
    next.delete("page");
    setParams(next);
  };

  const allerPage = (p) => {
    const next = new URLSearchParams(params);
    next.set("page", String(p));
    setParams(next);
  };

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Entretiens</h1>
          <p className="muted">{meta ? `${meta.total} entretien(s)` : "Liste des entretiens"}</p>
        </div>
        {user?.role === ROLES.ADMIN && <Link className="btn btn-primary" to="/entretien/nouveau">Nouvel entretien</Link>}
      </div>

      <div className="panel" style={{ display: "flex", gap: 12, flexWrap: "wrap", alignItems: "flex-end" }}>
        <label className="field" style={{ margin: 0 }}>
          <span>Statut</span>
          <select value={statut} onChange={(e) => setFiltre("statut", e.target.value)}>
            <option value="">Tous</option>
            {Object.entries(STATUTS).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
          </select>
        </label>
        <label className="field" style={{ margin: 0 }}>
          <span>Type</span>
          <select value={type} onChange={(e) => setFiltre("type", e.target.value)}>
            <option value="">Tous</option>
            {Object.entries(TYPES).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
          </select>
        </label>
        <label className="field" style={{ margin: 0 }}>
          <span>Vehicule (id)</span>
          <input type="number" min="1" value={vehicule} onChange={(e) => setFiltre("vehicule_id", e.target.value)} />
        </label>
        <label className="field" style={{ margin: 0 }}>
          <span>Archives</span>
          <select
            value={archives}
            onChange={(e) => setFiltre("archives", e.target.value === ARCHIVES.MASQUER ? "" : e.target.value)}
          >
            <option value={ARCHIVES.MASQUER}>Masquer</option>
            <option value={ARCHIVES.INCLURE}>Inclure</option>
            <option value={ARCHIVES.SEULEMENT}>Seulement</option>
          </select>
        </label>
        {(statut || type || vehicule || archives !== ARCHIVES.MASQUER) && (
          <button className="btn" onClick={() => setParams({})}>Reinitialiser</button>
        )}
      </div>

      {error && <p className="alert alert-bad" role="alert">{error}</p>}

      <div className="panel" style={{ overflowX: "auto" }}>
        {loading ? (
          <p className="muted">Chargement...</p>
        ) : rows.length === 0 ? (
          <p className="muted">Aucun entretien ne correspond aux filtres.</p>
        ) : (
          <table style={{ width: "100%", borderCollapse: "collapse" }}>
            <thead>
              <tr style={{ textAlign: "left", color: "var(--muted)", fontSize: "0.85rem" }}>
                <th style={{ padding: 8 }}>#</th>
                <th>Vehicule</th>
                <th>Type</th>
                <th>Categorie</th>
                <th>Date</th>
                <th>Km</th>
                <th className="right">Cout</th>
                <th style={{ paddingLeft: 16 }}>Statut</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {rows.map((e) => (
                <tr key={e.id} style={{ borderTop: "1px solid var(--line)" }}>
                  <td style={{ padding: 8 }}>{e.id}</td>
                  <td>#{e.vehicule_id}</td>
                  <td><TypeBadge type={e.type} /></td>
                  <td>{e.categorie}</td>
                  <td>{formatDate(e.date_entretien)}</td>
                  <td>{formatKm(e.kilometrage)}</td>
                  <td className="right">{formatMoney(e.cout)}</td>
                  <td style={{ paddingLeft: 16 }}>
                    <StatusBadge statut={e.statut} />
                    {e.archive && <span className="badge badge-mute" style={{ marginLeft: 6 }}>Archive</span>}
                  </td>
                  <td className="right"><Link to={`/entretien/${e.id}`}>Detail</Link></td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {meta && meta.last_page > 1 && (
        <div style={{ display: "flex", gap: 12, alignItems: "center", justifyContent: "center" }}>
          <button className="btn" disabled={page <= 1} onClick={() => allerPage(page - 1)}>Precedent</button>
          <span className="muted">Page {meta.current_page} / {meta.last_page}</span>
          <button className="btn" disabled={page >= meta.last_page} onClick={() => allerPage(page + 1)}>Suivant</button>
        </div>
      )}
    </>
  );
}