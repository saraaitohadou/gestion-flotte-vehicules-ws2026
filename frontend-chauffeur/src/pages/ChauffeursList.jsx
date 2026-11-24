import { useCallback, useEffect, useMemo, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { api } from "../api.js";
import { CATEGORIES, STATUTS, formatDate, initiales, normaliser, permisEtat } from "../utils.js";
import { PermisBadge, StatusBadge } from "../components/Badges.jsx";
import ConfirmDialog from "../components/ConfirmDialog.jsx";
import { useToast } from "../components/Toast.jsx";

const PAR_PAGE = 10;

export default function ChauffeursList() {
  const toast = useToast();
  const [params] = useSearchParams();
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const [q, setQ] = useState("");
  const [statut, setStatut] = useState(params.get("statut") ?? "");
  const [categorie, setCategorie] = useState("");
  const [permis, setPermis] = useState("");
  const [page, setPage] = useState(1);

  const [aSupprimer, setASupprimer] = useState(null);
  const [busy, setBusy] = useState(false);

  // Statut et catégorie sont filtrés par l'API, la recherche texte côté client
  const charger = useCallback(() => {
    setLoading(true);
    api.list({ statut, categorie_permis: categorie })
      .then((data) => { setItems(Array.isArray(data) ? data : (data?.items ?? [])); setError(""); })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [statut, categorie]);

  useEffect(() => { charger(); }, [charger]);
  useEffect(() => { setPage(1); }, [q, statut, categorie, permis]);

  const filtres = useMemo(() => {
    const terme = normaliser(q.trim());
    return items.filter((c) => {
      if (permis && permisEtat(c.date_expiration_permis).key !== permis) return false;
      if (!terme) return true;
      return normaliser([c.nom, c.prenom, c.cin, c.numero_permis, c.telephone].join(" ")).includes(terme);
    });
  }, [items, q, permis]);

  const pages = Math.max(1, Math.ceil(filtres.length / PAR_PAGE));
  const visibles = filtres.slice((page - 1) * PAR_PAGE, page * PAR_PAGE);
  const filtreActif = q || statut || categorie || permis;

  const reinitialiser = () => { setQ(""); setStatut(""); setCategorie(""); setPermis(""); };

  const supprimer = async () => {
    setBusy(true);
    try {
      await api.remove(aSupprimer.id_chauffeur);
      toast(`${aSupprimer.prenom} ${aSupprimer.nom} a été désactivé.`);
      setASupprimer(null);
      charger();
    } catch (e) {
      toast(e.message, "bad");
      setASupprimer(null);
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <header className="page-head">
        <div>
          <h1>Chauffeurs</h1>
          <p className="muted">{loading ? "Chargement..." : `${filtres.length} chauffeur${filtres.length > 1 ? "s" : ""}`}</p>
        </div>
        <Link className="btn btn-primary" to="/chauffeurs/nouveau">Ajouter un chauffeur</Link>
      </header>

      <section className="filters" aria-label="Recherche et filtres">
        <label className="field grow">
          <span>Rechercher</span>
          <input type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Nom, prénom, CIN, n° de permis, téléphone" />
        </label>
        <label className="field">
          <span>Statut</span>
          <select value={statut} onChange={(e) => setStatut(e.target.value)}>
            <option value="">Tous</option>
            {["DISPONIBLE", "EN_MISSION", "EN_CONGE"].map((s) => <option key={s} value={s}>{STATUTS[s].label}</option>)}
          </select>
        </label>
        <label className="field">
          <span>Catégorie de permis</span>
          <select value={categorie} onChange={(e) => setCategorie(e.target.value)}>
            <option value="">Toutes</option>
            {CATEGORIES.map((c) => <option key={c} value={c}>{c}</option>)}
          </select>
        </label>
        <label className="field">
          <span>État du permis</span>
          <select value={permis} onChange={(e) => setPermis(e.target.value)}>
            <option value="">Tous</option>
            <option value="valide">Valide</option>
            <option value="bientot">Expire sous 30 jours</option>
            <option value="expire">Expiré</option>
          </select>
        </label>
        {filtreActif && <button className="btn btn-ghost" onClick={reinitialiser}>Effacer les filtres</button>}
      </section>

      {error && <p className="alert alert-bad">{error}</p>}

      <div className="table-wrap">
        <table className="table">
          <thead>
            <tr>
              <th>Chauffeur</th><th>CIN</th><th>Permis</th><th>Expiration</th><th>Véhicule</th><th>Statut</th><th className="right">Actions</th>
            </tr>
          </thead>
          <tbody>
            {visibles.map((c) => (
              <tr key={c.id_chauffeur}>
                <td>
                  <Link to={`/chauffeurs/${c.id_chauffeur}`} className="person">
                    <span className="avatar">{initiales(c)}</span>
                    <span><strong>{c.prenom} {c.nom}</strong><span className="muted small block">{c.telephone || "Pas de téléphone"}</span></span>
                  </Link>
                </td>
                <td>{c.cin}</td>
                <td>{c.numero_permis} <span className="muted small">(cat. {c.categorie_permis})</span></td>
                <td>{formatDate(c.date_expiration_permis)} <PermisBadge date={c.date_expiration_permis} /></td>
                <td>
                  {c.vehicule_id ? (
                    <span className="badge badge-info" title={c.vehicule ? `${c.vehicule.marque || ""} ${c.vehicule.modele || ""} (${c.vehicule.immatriculation || ""})` : `Véhicule #${c.vehicule_id}`}>
                      #{c.vehicule_id} {c.vehicule?.immatriculation ? `(${c.vehicule.immatriculation})` : ""}
                    </span>
                  ) : (
                    <span className="muted">—</span>
                  )}
                </td>
                <td><StatusBadge statut={c.statut} /></td>
                <td className="right actions">
                  <Link className="btn btn-sm" to={`/chauffeurs/${c.id_chauffeur}`}>Profil</Link>
                  <Link className="btn btn-sm" to={`/chauffeurs/${c.id_chauffeur}/modifier`}>Modifier</Link>
                  <button className="btn btn-sm btn-danger-ghost" onClick={() => setASupprimer(c)} disabled={c.statut === "EN_MISSION"} title={c.statut === "EN_MISSION" ? "Clôturez d'abord la mission" : undefined}>
                    Supprimer
                  </button>
                </td>
              </tr>
            ))}
            {!loading && visibles.length === 0 && (
              <tr><td colSpan="7" className="empty">
                {filtreActif ? "Aucun chauffeur ne correspond à votre recherche." : "Aucun chauffeur enregistré. Ajoutez le premier."}
              </td></tr>
            )}
          </tbody>
        </table>
      </div>

      {pages > 1 && (
        <nav className="pager" aria-label="Pagination">
          <button className="btn btn-sm" disabled={page === 1} onClick={() => setPage(page - 1)}>Précédent</button>
          <span>Page {page} sur {pages}</span>
          <button className="btn btn-sm" disabled={page === pages} onClick={() => setPage(page + 1)}>Suivant</button>
        </nav>
      )}

      {aSupprimer && (
        <ConfirmDialog title="Désactiver ce chauffeur ?" confirmLabel="Désactiver" busy={busy} onConfirm={supprimer} onCancel={() => setASupprimer(null)}>
          <p><strong>{aSupprimer.prenom} {aSupprimer.nom}</strong> n'apparaîtra plus dans la liste. Ses données sont conservées et vous pourrez le réactiver plus tard.</p>
        </ConfirmDialog>
      )}
    </>
  );
}
