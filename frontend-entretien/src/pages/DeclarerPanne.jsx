import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { api, messageErreur } from "../api.js";
import { useAuth } from "../components/AuthContext.jsx";
import { useToast } from "../components/Toast.jsx";
import { ROLES } from "../roles.js";

export default function DeclarerPanne() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const toast = useToast();
  const estChauffeur = user?.role === ROLES.CHAUF;

  const [form, setForm] = useState({ vehicule_id: "", chauffeur_id: estChauffeur ? String(user.username) : "", categorie: "PANNE", description: "", kilometrage: "" });
  const [errors, setErrors] = useState({});
  const [globalError, setGlobalError] = useState("");
  const [saving, setSaving] = useState(false);
  const [vehicules, setVehicules] = useState([]);
  const [chauffeurs, setChauffeurs] = useState([]);
  const [erreurVehicules, setErreurVehicules] = useState("");
  const [erreurChauffeurs, setErreurChauffeurs] = useState("");
  const [chargementVehicules, setChargementVehicules] = useState(!estChauffeur);
  const [chargementChauffeurs, setChargementChauffeurs] = useState(!estChauffeur);
  const [tentative, setTentative] = useState(0);
  const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));

  // Listes reservees a l'admin et au gestionnaire (le chauffeur recevrait un 403).
  // Chaque echec est affiche : plus de catch silencieux.
  useEffect(() => {
    if (estChauffeur) {
      setChargementVehicules(false);
      setChargementChauffeurs(false);
      return;
    }
    let actif = true;
    setChargementVehicules(true);
    setChargementChauffeurs(true);
    setErreurVehicules("");
    setErreurChauffeurs("");

    api.vehicules()
      .then((r) => { if (actif) setVehicules(r.data ?? []); })
      .catch((e) => { if (actif) { setVehicules([]); setErreurVehicules(messageErreur(e, "charger les vehicules")); } })
      .finally(() => { if (actif) setChargementVehicules(false); });

    api.chauffeurs()
      .then((r) => { if (actif) setChauffeurs(r.data ?? []); })
      .catch((e) => { if (actif) { setChauffeurs([]); setErreurChauffeurs(messageErreur(e, "charger les chauffeurs")); } })
      .finally(() => { if (actif) setChargementChauffeurs(false); });

    return () => { actif = false; };
  }, [estChauffeur, tentative]);

  const pretAAjouter = estChauffeur
    || (!chargementVehicules && !chargementChauffeurs && !erreurVehicules && !erreurChauffeurs);

  const envoyer = async (ev) => {
    ev.preventDefault();
    setGlobalError("");
    if (!pretAAjouter) {
      setGlobalError(erreurVehicules || erreurChauffeurs || "Veuillez attendre le chargement des listes.");
      return;
    }
    const e = {};
    if (!form.vehicule_id) e.vehicule_id = "Vehicule obligatoire.";
    if (!estChauffeur && !form.chauffeur_id) e.chauffeur_id = "Chauffeur obligatoire.";
    if (!form.description.trim()) e.description = "Description obligatoire.";
    if (form.kilometrage === "" || Number(form.kilometrage) < 0) e.kilometrage = "Kilometrage obligatoire (>= 0).";
    setErrors(e);
    if (Object.keys(e).length) return;

    // Le chauffeur est identifie par son JWT : aucun chauffeur_id n'est envoye.
    const corps = {
      vehicule_id: Number(form.vehicule_id),
      categorie: form.categorie,
      description: form.description,
      kilometrage: Number(form.kilometrage),
    };
    if (!estChauffeur) corps.chauffeur_id = Number(form.chauffeur_id);

    setSaving(true);
    try {
      await api.declarer(corps);
      toast("Panne declaree.");
      navigate(estChauffeur ? "/pannes/mes-declarations" : "/entretien/declarations");
    } catch (err) {
      if (err.fields) setErrors(err.fields);
      setGlobalError(messageErreur(err, "declarer la panne"));
    } finally {
      setSaving(false);
    }
  };

  const champ = (nom, label, input) => (
    <label className="field">
      <span>{label}</span>
      {input}
      {errors[nom] && <span className="error small">{errors[nom]}</span>}
    </label>
  );

  const libelleVehicule = chargementVehicules
    ? "Chargement des vehicules..."
    : erreurVehicules
      ? "Vehicules indisponibles"
      : vehicules.length
        ? "-- Choisir un vehicule --"
        : "Aucun vehicule disponible";

  const libelleChauffeur = chargementChauffeurs
    ? "Chargement des chauffeurs..."
    : erreurChauffeurs
      ? "Chauffeurs indisponibles"
      : chauffeurs.length
        ? "-- Choisir un chauffeur --"
        : "Aucun chauffeur disponible";

  const champVehicule = estChauffeur
    ? champ("vehicule_id", "Vehicule (id)", <input type="number" min="1" value={form.vehicule_id} onChange={(e) => set("vehicule_id", e.target.value)} />)
    : champ("vehicule_id", "Vehicule", (
      <select value={form.vehicule_id} onChange={(e) => set("vehicule_id", e.target.value)} disabled={chargementVehicules || Boolean(erreurVehicules)}>
        <option value="">{libelleVehicule}</option>
        {vehicules.map((v) => (
          <option key={v.id} value={v.id}>
            {v.immatriculation} - {v.marque} {v.modele} ({Number(v.kilometrage).toLocaleString("fr-FR")} km, {v.statut})
          </option>
        ))}
      </select>
    ));

  const champChauffeur = estChauffeur
    ? champ("chauffeur_id", "Chauffeur (id)", <input type="number" value={form.chauffeur_id} disabled />)
    : champ("chauffeur_id", "Chauffeur", (
      <select value={form.chauffeur_id} onChange={(e) => set("chauffeur_id", e.target.value)} disabled={chargementChauffeurs || Boolean(erreurChauffeurs)}>
        <option value="">{libelleChauffeur}</option>
        {chauffeurs.map((c) => (
          <option key={c.id_chauffeur ?? c.id} value={c.id_chauffeur ?? c.id}>
            {c.nom} {c.prenom} ({c.cin}{c.statut ? `, ${c.statut}` : ""})
          </option>
        ))}
      </select>
    ));

  return (
    <>
      <div className="page-head"><h1>Declarer une panne</h1></div>
      {erreurVehicules && !estChauffeur && (
        <p className="alert alert-bad" role="alert" data-testid="vehicules-error">
          {erreurVehicules}{" "}
          <button type="button" className="btn btn-small" onClick={() => setTentative((n) => n + 1)}>Reessayer</button>
        </p>
      )}
      {erreurChauffeurs && !estChauffeur && (
        <p className="alert alert-bad" role="alert" data-testid="chauffeurs-error">
          {erreurChauffeurs}{" "}
          <button type="button" className="btn btn-small" onClick={() => setTentative((n) => n + 1)}>Reessayer</button>
        </p>
      )}
      {globalError && <p className="alert alert-bad" role="alert">{globalError}</p>}
      <form className="panel" onSubmit={envoyer} noValidate>
        <fieldset disabled={!pretAAjouter || saving} style={{ border: 0, padding: 0, margin: 0 }}>
          <div className="grid-2">
            {champVehicule}
            {champChauffeur}
            {champ("categorie", "Categorie", (
              <select value={form.categorie} onChange={(e) => set("categorie", e.target.value)}>
                <option value="PANNE">PANNE</option>
                <option value="REPARATION">REPARATION</option>
              </select>
            ))}
            {champ("kilometrage", "Kilometrage actuel", <input type="number" min="0" value={form.kilometrage} onChange={(e) => set("kilometrage", e.target.value)} />)}
          </div>
          {champ("description", "Description de la panne", <textarea rows="3" value={form.description} onChange={(e) => set("description", e.target.value)} />)}
          <button className="btn btn-primary" disabled={!pretAAjouter || saving}>{saving ? "Envoi..." : "Declarer"}</button>
        </fieldset>
      </form>
    </>
  );
}
