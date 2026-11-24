import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { api, messageErreur } from "../api.js";
import { formatKm, peutCreerEntretien, VEHICULE_BADGES } from "../utils.js";

const listeVehicules = (reponse) => {
  const liste = Array.isArray(reponse) ? reponse : (reponse?.data ?? []);
  return Array.isArray(liste) ? liste : [];
};

export default function Vehicules() {
  const [vehicules, setVehicules] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [recherche, setRecherche] = useState("");
  const [tentative, setTentative] = useState(0);

  useEffect(() => {
    let actif = true;
    setLoading(true);
    setError("");
    api.vehicules()
      .then((r) => { if (actif) setVehicules(listeVehicules(r)); })
      .catch((e) => {
        if (!actif) return;
        setVehicules([]);
        setError(messageErreur(e, "charger les vehicules"));
      })
      .finally(() => { if (actif) setLoading(false); });
    return () => { actif = false; };
  }, [tentative]);

  const filtre = recherche.trim().toLocaleLowerCase("fr-FR");
  const visibles = vehicules.filter((v) => {
    if (!filtre) return true;
    return [v.immatriculation, v.marque, v.modele]
      .filter(Boolean)
      .some((valeur) => String(valeur).toLocaleLowerCase("fr-FR").includes(filtre));
  });

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Vehicules</h1>
          <p className="muted">
            {loading ? "Chargement..." : `${visibles.length} vehicule(s)`}
          </p>
        </div>
        <Link className="btn" to="/entretien/nouveau">Nouvel entretien</Link>
      </div>

      {error && (
        <p className="alert alert-bad" role="alert" data-testid="vehicules-error">
          {error}{" "}
          <button type="button" className="btn btn-small" onClick={() => setTentative((n) => n + 1)}>
            Reessayer
          </button>
        </p>
      )}

      <div className="panel" style={{ display: "flex", gap: 12, flexWrap: "wrap", alignItems: "flex-end" }}>
        <label className="field" style={{ margin: 0, flex: "1 1 240px" }}>
          <span>Rechercher un vehicule</span>
          <input
            type="search"
            placeholder="Immatriculation, marque ou modele"
            value={recherche}
            onChange={(e) => setRecherche(e.target.value)}
          />
        </label>
      </div>

      <div className="table-wrap">
        <table className="table">
          <thead>
            <tr>
              <th>Immatriculation</th>
              <th>Marque / modele</th>
              <th>Kilometrage</th>
              <th>Statut</th>
              <th className="right">Action</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr><td className="empty" colSpan="5">Chargement...</td></tr>
            ) : visibles.length === 0 ? (
              <tr><td className="empty" colSpan="5">Aucun vehicule ne correspond a la recherche.</td></tr>
            ) : (
              visibles.map((v) => {
                const autorise = peutCreerEntretien(v);
                const badge = VEHICULE_BADGES[String(v.statut ?? "").toUpperCase()] ?? { label: v.statut ?? "-", tone: "mute" };
                const lien = `/entretien/nouveau?vehicule_id=${encodeURIComponent(v.id)}&source=vehicules`;
                return (
                  <tr key={v.id}>
                    <td><strong>{v.immatriculation ?? `#${v.id}`}</strong></td>
                    <td>{[v.marque, v.modele].filter(Boolean).join(" ") || "-"}</td>
                    <td>{formatKm(v.kilometrage)}</td>
                    <td><span className={`badge badge-${badge.tone}`}>{badge.label}</span></td>
                    <td className="right">
                      {autorise ? (
                        <Link className="btn btn-small btn-primary" to={lien}>Planifier un entretien</Link>
                      ) : (
                        <span className="muted" title="Statut interdit pour la creation d'un entretien">Indisponible</span>
                      )}
                    </td>
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </div>
    </>
  );
}
