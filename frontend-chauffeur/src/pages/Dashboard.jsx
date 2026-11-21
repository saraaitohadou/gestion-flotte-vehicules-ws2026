import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { api } from "../api.js";
import { STATUTS, formatDate, initiales, permisEtat } from "../utils.js";
import { PermisBadge, StatusBadge } from "../components/Badges.jsx";

export default function Dashboard() {
  const [chauffeurs, setChauffeurs] = useState(null);
  const [permis, setPermis] = useState([]);
  const [error, setError] = useState("");

  useEffect(() => {
    Promise.all([api.list(), api.permisExpires(30)])
      .then(([liste, expires]) => { setChauffeurs(liste); setPermis(expires); })
      .catch((e) => setError(e.message));
  }, []);

  if (error) return <p className="alert alert-bad">{error}</p>;
  if (!chauffeurs) return <p className="muted">Chargement du tableau de bord...</p>;

  const compte = (s) => chauffeurs.filter((c) => c.statut === s).length;
  const total = chauffeurs.length;
  const recents = [...chauffeurs].sort((a, b) => b.id_chauffeur - a.id_chauffeur).slice(0, 5);
  const expires = permis.filter((c) => permisEtat(c.date_expiration_permis).key === "expire").length;

  return (
    <>
      <header className="page-head">
        <div>
          <h1>Tableau de bord</h1>
          <p className="muted">Vue d'ensemble de vos chauffeurs actifs.</p>
        </div>
        <Link className="btn btn-primary" to="/chauffeurs/nouveau">Ajouter un chauffeur</Link>
      </header>

      <section className="kpis">
        <div className="kpi"><span className="kpi-value">{total}</span><span>Chauffeurs actifs</span></div>
        <div className="kpi"><span className="kpi-value">{compte("DISPONIBLE")}</span><span>Disponibles</span></div>
        <div className="kpi"><span className="kpi-value">{compte("EN_MISSION")}</span><span>En mission</span></div>
        <div className={`kpi ${permis.length ? "kpi-alert" : ""}`}>
          <span className="kpi-value">{permis.length}</span>
          <span>Permis à renouveler{expires ? ` (${expires} expiré${expires > 1 ? "s" : ""})` : ""}</span>
        </div>
      </section>

      <div className="grid-2">
        <section className="panel">
          <h2>Répartition par statut</h2>
          {["DISPONIBLE", "EN_MISSION", "EN_CONGE"].map((s) => {
            const n = compte(s);
            return (
              <Link key={s} to={`/chauffeurs?statut=${s}`} className="bar-row">
                <span>{STATUTS[s].label}</span>
                <span className="bar"><span className={`bar-fill tone-${STATUTS[s].tone}`} style={{ width: total ? `${(n / total) * 100}%` : 0 }} /></span>
                <strong>{n}</strong>
              </Link>
            );
          })}
        </section>

        <section className="panel">
          <h2>Permis expirés ou proches de l'échéance</h2>
          {permis.length === 0 ? (
            <p className="muted">Aucun permis à renouveler dans les 30 prochains jours.</p>
          ) : (
            <ul className="list">
              {permis.map((c) => (
                <li key={c.id_chauffeur}>
                  <Link to={`/chauffeurs/${c.id_chauffeur}`} className="grow">{c.prenom} {c.nom}</Link>
                  <span className="muted small">{formatDate(c.date_expiration_permis)}</span>
                  <PermisBadge date={c.date_expiration_permis} />
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>

      <section className="panel">
        <div className="panel-head">
          <h2>Derniers chauffeurs ajoutés</h2>
          <Link to="/chauffeurs">Voir toute la liste</Link>
        </div>
        {recents.length === 0 ? (
          <p className="muted">Aucun chauffeur pour le moment. Commencez par en ajouter un.</p>
        ) : (
          <ul className="list">
            {recents.map((c) => (
              <li key={c.id_chauffeur}>
                <span className="avatar">{initiales(c)}</span>
                <Link to={`/chauffeurs/${c.id_chauffeur}`} className="grow">{c.prenom} {c.nom}</Link>
                <span className="muted small">Permis {c.categorie_permis}</span>
                <StatusBadge statut={c.statut} />
              </li>
            ))}
          </ul>
        )}
      </section>
    </>
  );
}
