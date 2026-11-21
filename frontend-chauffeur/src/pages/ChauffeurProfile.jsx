import { useCallback, useEffect, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { api } from "../api.js";
import { CATEGORIES, MOTIFS, STATUTS, TRANSITIONS, formatDate, initiales, permisEtat } from "../utils.js";
import { PermisBadge, StatusBadge } from "../components/Badges.jsx";
import ConfirmDialog from "../components/ConfirmDialog.jsx";
import { useToast } from "../components/Toast.jsx";

export default function ChauffeurProfile() {
  const { id } = useParams();
  const navigate = useNavigate();
  const toast = useToast();

  const [c, setC] = useState(null);
  const [elig, setElig] = useState(null);
  const [error, setError] = useState("");
  const [suppression, setSuppression] = useState(false);
  const [busy, setBusy] = useState(false);
  const [catVehicule, setCatVehicule] = useState("");
  const [compat, setCompat] = useState(null);

  const charger = useCallback(() => {
    Promise.all([api.get(id), api.eligibilite(id)])
      .then(([chauffeur, e]) => { setC(chauffeur); setElig(e); setError(""); })
      .catch((e) => setError(e.message));
  }, [id]);

  useEffect(() => { charger(); }, [charger]);

  const changerStatut = async (statut) => {
    try {
      await api.setStatut(id, statut);
      toast(`Statut changé : ${STATUTS[statut].label}.`);
      charger();
    } catch (e) { toast(e.message, "bad"); }
  };

  const supprimer = async () => {
    setBusy(true);
    try {
      await api.remove(id);
      toast("Chauffeur désactivé.");
      navigate("/chauffeurs");
    } catch (e) {
      toast(e.message, "bad");
      setSuppression(false);
    } finally { setBusy(false); }
  };

  const verifierCompat = async (cat) => {
    setCatVehicule(cat);
    setCompat(cat ? await api.compatibilite(id, cat).catch((e) => ({ erreur: e.message })) : null);
  };

  if (error) return (
    <>
      <p className="alert alert-bad">{error}</p>
      <Link className="btn" to="/chauffeurs">Retour à la liste</Link>
    </>
  );
  if (!c) return <p className="muted">Chargement du profil...</p>;

  const etat = permisEtat(c.date_expiration_permis);

  return (
    <>
      <header className="page-head">
        <div>
          <Link to="/chauffeurs" className="back">Retour à la liste</Link>
          <h1>{c.prenom} {c.nom}</h1>
        </div>
        <div className="actions">
          <Link className="btn" to={`/chauffeurs/${id}/modifier`}>Modifier</Link>
          <button className="btn btn-danger-ghost" onClick={() => setSuppression(true)} disabled={c.statut === "EN_MISSION"} title={c.statut === "EN_MISSION" ? "Clôturez d'abord la mission" : undefined}>
            Supprimer
          </button>
        </div>
      </header>

      <div className="profile-grid">
        <article className={`licence licence-${etat.tone}`} aria-label="Permis de conduire">
          <div className="licence-top">
            <span className="avatar avatar-lg">{initiales(c)}</span>
            <div>
              <strong>{c.prenom} {c.nom}</strong>
              <span>CIN {c.cin}</span>
            </div>
            <span className="licence-cat" title="Catégorie de permis">{c.categorie_permis}</span>
          </div>
          <dl>
            <div><dt>Numéro de permis</dt><dd>{c.numero_permis}</dd></div>
            <div><dt>Expire le</dt><dd>{formatDate(c.date_expiration_permis)}</dd></div>
            <div><dt>Téléphone</dt><dd>{c.telephone || "Non renseigné"}</dd></div>
          </dl>
          <div className="licence-foot"><PermisBadge date={c.date_expiration_permis} /></div>
        </article>

        <div className="stack">
          <section className="panel">
            <h2>Statut & Véhicule</h2>
            <p><StatusBadge statut={c.statut} /></p>
            <div style={{ marginTop: "8px", fontSize: "0.95rem" }}>
              <strong>Véhicule assigné : </strong>
              {c.vehicule_id ? (
                <span className="badge badge-info" style={{ marginLeft: "6px" }}>
                  #{c.vehicule_id} {c.vehicule ? `— ${c.vehicule.marque || ""} ${c.vehicule.modele || ""} (${c.vehicule.immatriculation || ""})` : ""}
                </span>
              ) : (
                <span className="muted">Aucun</span>
              )}
            </div>
            {TRANSITIONS[c.statut]?.length > 0 && (
              <div className="actions" style={{ marginTop: "12px" }}>
                {TRANSITIONS[c.statut].map((s) => (
                  <button key={s} className="btn btn-sm" onClick={() => changerStatut(s)}>Passer en « {STATUTS[s].label} »</button>
                ))}
              </div>
            )}
          </section>

          <section className="panel">
            <h2>Éligibilité</h2>
            {elig && (
              <ul className="checks">
                <li className={elig.selectionnable ? "yes" : "no"}>{elig.selectionnable ? "Sélectionnable par les autres modules" : "Non sélectionnable"}</li>
                <li className={elig.affectable ? "yes" : "no"}>{elig.affectable ? "Peut être affecté à une mission" : "Ne peut pas être affecté à une mission"}</li>
              </ul>
            )}
            {elig?.motifs?.map((m) => <p key={m} className="small error">{MOTIFS[m] ?? m}</p>)}
          </section>

          <section className="panel">
            <h2>Compatibilité avec un véhicule</h2>
            <label className="field">
              <span>Catégorie du véhicule</span>
              <select value={catVehicule} onChange={(e) => verifierCompat(e.target.value)}>
                <option value="">Choisir une catégorie</option>
                {CATEGORIES.map((x) => <option key={x} value={x}>{x}</option>)}
              </select>
            </label>
            {compat && !compat.erreur && (
              <p className={compat.compatible ? "yes-text" : "error"}>
                {compat.compatible ? `Le permis ${c.categorie_permis} permet de conduire cette catégorie.` : `Le permis ${c.categorie_permis} ne permet pas de conduire cette catégorie.`}
              </p>
            )}
            {compat?.erreur && <p className="error">{compat.erreur}</p>}
          </section>
        </div>
      </div>

      {suppression && (
        <ConfirmDialog title="Désactiver ce chauffeur ?" confirmLabel="Désactiver" busy={busy} onConfirm={supprimer} onCancel={() => setSuppression(false)}>
          <p>Le profil de <strong>{c.prenom} {c.nom}</strong> sera masqué. Ses données restent conservées.</p>
        </ConfirmDialog>
      )}
    </>
  );
}
