import { useCallback, useEffect, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { api, messageErreur } from "../api.js";
import { useAuth } from "../components/AuthContext.jsx";
import { StatusBadge, TypeBadge } from "../components/Badges.jsx";
import ConfirmDialog from "../components/ConfirmDialog.jsx";
import { useToast } from "../components/Toast.jsx";
import { ROLES } from "../roles.js";
import { formatDate, formatKm, formatMoney } from "../utils.js";

const dt = (iso) => (iso ? new Date(iso).toLocaleString("fr-FR") : "-");

export default function EntretienDetail() {
  const { id } = useParams();
  const { user } = useAuth();
  const navigate = useNavigate();
  const toast = useToast();

  const [e, setE] = useState(null);
  const [vehicule, setVehicule] = useState(null);
  const [historique, setHistorique] = useState([]);
  const [onglet, setOnglet] = useState("infos");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [confirm, setConfirm] = useState(null); // "annuler" | "archiver"

  const charger = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const { data } = await api.get(id);
      setE(data);
      // Etat authoritative du vehicule : verifie en direct apres chaque transition.
      try {
        const v = await api.vehicule(data.vehicule_id);
        setVehicule(v.data ?? null);
      } catch {
        setVehicule(null);
      }
      const h = await api.historiqueVehicule(data.vehicule_id);
      setHistorique(h.data ?? []);
    } catch (err) {
      setError(messageErreur(err, "charger l'entretien"));
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => { charger(); }, [charger]);

  const agir = async (fn, message, redirect) => {
    setBusy(true);
    setError("");
    try {
      await fn();
      toast(message);
      setConfirm(null);
      if (redirect) navigate(redirect);
      else await charger();
    } catch (err) {
      setConfirm(null);
      setError(messageErreur(err, "realiser l'action"));
    } finally {
      setBusy(false);
    }
  };

  if (loading && !e) return <p className="muted">Chargement...</p>;
  if (!e) return <p className="alert alert-bad" role="alert">{error || "Entretien introuvable."}</p>;

  const estAdmin = user?.role === ROLES.ADMIN;
  const actif = !e.archive && ["PLANIFIE", "EN_COURS"].includes(e.statut);
  const archivable = estAdmin && !e.archive && ["TERMINE", "ANNULE"].includes(e.statut);

  const ligne = (label, valeur) => (
    <div style={{ display: "flex", justifyContent: "space-between", padding: "8px 0", borderTop: "1px solid var(--line)" }}>
      <span className="muted">{label}</span><strong>{valeur}</strong>
    </div>
  );

  return (
    <>
      <div className="page-head">
        <div>
          <Link className="back" to="/entretien/liste">&larr; Retour a la liste</Link>
          <h1>Entretien #{e.id}</h1>
          <p style={{ display: "flex", gap: 8, alignItems: "center" }}>
            <TypeBadge type={e.type} /> <StatusBadge statut={e.statut} />
            {e.archive && <span className="badge badge-mute">Archive</span>}
          </p>
        </div>
        <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
          {e.statut === "PLANIFIE" && !e.archive && (
            <button className="btn btn-primary" disabled={busy} onClick={() => agir(() => api.demarrer(e.id), "Entretien demarre.")}>Demarrer</button>
          )}
          {e.statut === "EN_COURS" && !e.archive && (
            <button className="btn btn-primary" disabled={busy} onClick={() => agir(() => api.terminer(e.id), "Entretien termine.")}>Terminer</button>
          )}
          {actif && <button className="btn" disabled={busy} onClick={() => setConfirm("annuler")}>Annuler l'entretien</button>}
          {actif && <Link className="btn" to={`/entretien/${e.id}/modifier`}>Modifier</Link>}
          {archivable && <button className="btn" disabled={busy} onClick={() => setConfirm("archiver")}>Archiver</button>}
        </div>
      </div>

      {error && <p className="alert alert-bad" role="alert">{error}</p>}

      <div style={{ display: "flex", gap: 8, marginBottom: 12 }}>
        <button className={`btn ${onglet === "infos" ? "btn-primary" : ""}`} onClick={() => setOnglet("infos")}>Informations</button>
        <button className={`btn ${onglet === "histo" ? "btn-primary" : ""}`} onClick={() => setOnglet("histo")}>
          Historique du vehicule #{e.vehicule_id} ({historique.length})
        </button>
        <button className={`btn ${onglet === "statuts" ? "btn-primary" : ""}`} onClick={() => setOnglet("statuts")}>Statuts ({e.historique?.length ?? 0})</button>
      </div>

      {onglet === "infos" && (
        <div className="grid-2">
          <div className="panel">
            <h2>Details</h2>
            {ligne("Vehicule", `#${e.vehicule_id}`)}
            {ligne("Statut vehicule", vehicule
              ? `${vehicule.statut} (${formatKm(vehicule.kilometrage)})`
              : "etat indisponible")}
            {ligne("Categorie", e.categorie)}
            {ligne("Date", formatDate(e.date_entretien))}
            {ligne("Kilometrage", formatKm(e.kilometrage))}
            {ligne("Cout", formatMoney(e.cout))}
            {ligne("Prochaine echeance", `${formatDate(e.prochaine_echeance?.date)} / ${formatKm(e.prochaine_echeance?.kilometrage)}`)}
            <p style={{ marginTop: 12 }}>{e.description}</p>
          </div>
          <div className="panel">
            <h2>Suivi</h2>
            {ligne("Debut", dt(e.date_debut))}
            {ligne("Fin", dt(e.date_fin))}
            {ligne("Cree par", e.responsables?.cree_par ?? "-")}
            {ligne("Demarre par", e.responsables?.demarre_par ?? "-")}
            {ligne("Termine par", e.responsables?.termine_par ?? "-")}
            {ligne("Annule par", e.responsables?.annule_par ?? "-")}
            {ligne("Archive par", e.responsables?.archive_par ?? "-")}
          </div>
        </div>
      )}

      {onglet === "statuts" && (
        <div className="panel">
          {(e.historique ?? []).length === 0 ? <p className="muted">Aucun changement de statut enregistre.</p> : (
            <ol style={{ margin: 0, paddingLeft: 20 }}>
              {e.historique.map((h, i) => (
                <li key={i} style={{ padding: "6px 0" }}>
                  <strong>{h.ancien_statut ? `${h.ancien_statut} -> ${h.nouveau_statut}` : `Cree (${h.nouveau_statut})`}</strong>
                  <span className="muted"> | par {h.action_par ?? "-"} | {dt(h.date)}</span>
                </li>
              ))}
            </ol>
          )}
        </div>
      )}

      {onglet === "histo" && (
        <div className="panel" style={{ overflowX: "auto" }}>
          {historique.length === 0 ? <p className="muted">Aucun entretien pour ce vehicule.</p> : (
            <table style={{ width: "100%", borderCollapse: "collapse" }}>
              <thead>
                <tr style={{ textAlign: "left", color: "var(--muted)", fontSize: "0.85rem" }}>
                  <th style={{ padding: 8 }}>#</th><th>Type</th><th>Categorie</th><th>Date</th><th>Km</th><th>Statut</th><th></th>
                </tr>
              </thead>
              <tbody>
                {historique.map((h) => (
                  <tr key={h.id} style={{ borderTop: "1px solid var(--line)", background: h.id === e.id ? "var(--info-bg)" : undefined }}>
                    <td style={{ padding: 8 }}>{h.id}</td>
                    <td><TypeBadge type={h.type} /></td>
                    <td>{h.categorie}</td>
                    <td>{formatDate(h.date_entretien)}</td>
                    <td>{formatKm(h.kilometrage)}</td>
                    <td><StatusBadge statut={h.statut} /></td>
                    <td className="right"><Link to={`/entretien/${h.id}`}>Detail</Link></td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}

      {confirm === "annuler" && (
        <ConfirmDialog
          title="Annuler l'entretien ?"
          confirmLabel="Annuler l'entretien"
          busy={busy}
          onCancel={() => setConfirm(null)}
          onConfirm={() => agir(() => api.annuler(e.id), "Entretien annule.")}
        >
          Le statut passera a ANNULE (pas de retour en arriere).
        </ConfirmDialog>
      )}
      {confirm === "archiver" && (
        <ConfirmDialog
          title="Archiver l'entretien ?"
          confirmLabel="Archiver"
          busy={busy}
          onCancel={() => setConfirm(null)}
          onConfirm={() => agir(() => api.archiver(e.id), "Entretien archive.", "/entretien/liste")}
        >
          L'entretien sera archive (aucune suppression physique).
        </ConfirmDialog>
      )}
    </>
  );
}