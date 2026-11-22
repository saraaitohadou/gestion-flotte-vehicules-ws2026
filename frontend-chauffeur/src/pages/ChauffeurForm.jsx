import { useEffect, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { api } from "../api.js";
import { CATEGORIES, joursRestants } from "../utils.js";
import { useToast } from "../components/Toast.jsx";

const VIDE = {
  cin: "",
  nom: "",
  prenom: "",
  telephone: "",
  numero_permis: "",
  categorie_permis: "B",
  date_expiration_permis: "",
  vehicule_id: "",
};
const CIN_RE = /^[A-Z]{1,2}\d{5,6}$/;
const TEL_RE = /^(?:\+212|0)[67]\d{8}$/;

function valider(f, edition) {
  const e = {};
  if (!CIN_RE.test(f.cin.trim().toUpperCase())) e.cin = "1 ou 2 lettres suivies de 5 ou 6 chiffres (ex. AB123456).";
  if (!f.nom.trim()) e.nom = "Le nom est obligatoire.";
  if (!f.prenom.trim()) e.prenom = "Le prénom est obligatoire.";
  if (f.telephone.trim() && !TEL_RE.test(f.telephone.replace(/\s/g, ""))) e.telephone = "Format attendu : 06XXXXXXXX, 07XXXXXXXX ou +2126XXXXXXXX.";
  if (!f.numero_permis.trim()) e.numero_permis = "Le numéro de permis est obligatoire.";
  if (!f.date_expiration_permis) e.date_expiration_permis = "La date d'expiration est obligatoire.";
  else if (!edition && joursRestants(f.date_expiration_permis) <= 0) e.date_expiration_permis = "Le permis doit expirer après aujourd'hui.";
  return e;
}

export default function ChauffeurForm() {
  const { id } = useParams();
  const edition = Boolean(id);
  const navigate = useNavigate();
  const toast = useToast();

  const [form, setForm] = useState(VIDE);
  const [vehicules, setVehicules] = useState([]);
  const [errors, setErrors] = useState({});
  const [globalError, setGlobalError] = useState(null);
  const [loading, setLoading] = useState(edition);
  const [saving, setSaving] = useState(false);

  // Chargement de la liste des véhicules depuis vehicule-service
  useEffect(() => {
    api.vehicules()
      .then((data) => setVehicules(Array.isArray(data) ? data : []))
      .catch(() => setVehicules([]));
  }, []);

  useEffect(() => {
    if (!edition) return;
    api.get(id)
      .then((c) => setForm({
        cin: c.cin,
        nom: c.nom,
        prenom: c.prenom,
        telephone: c.telephone ?? "",
        numero_permis: c.numero_permis,
        categorie_permis: c.categorie_permis,
        date_expiration_permis: c.date_expiration_permis,
        vehicule_id: c.vehicule_id ? String(c.vehicule_id) : "",
      }))
      .catch((e) => setGlobalError({ message: e.message }))
      .finally(() => setLoading(false));
  }, [id, edition]);

  const set = (champ) => (e) => {
    setForm((f) => ({ ...f, [champ]: e.target.value }));
    setErrors((er) => ({ ...er, [champ]: undefined }));
  };

  const envoyer = async (e) => {
    e.preventDefault();
    setGlobalError(null);
    const er = valider(form, edition);
    setErrors(er);
    if (Object.keys(er).length) return;

    const payload = {
      cin: form.cin.trim().toUpperCase(),
      nom: form.nom.trim(),
      prenom: form.prenom.trim(),
      telephone: form.telephone.replace(/\s/g, "") || null,
      numero_permis: form.numero_permis.trim(),
      categorie_permis: form.categorie_permis,
      date_expiration_permis: form.date_expiration_permis,
      vehicule_id: form.vehicule_id ? parseInt(form.vehicule_id, 10) : null,
    };

    setSaving(true);
    try {
      const c = edition ? await api.update(id, payload) : await api.create(payload);
      toast(edition ? "Modifications enregistrées." : `${c.prenom} ${c.nom} a été ajouté.`);
      navigate(`/chauffeurs/${c.id_chauffeur}`);
    } catch (err) {
      if (err.fields) setErrors(err.fields);
      setGlobalError({ message: err.message, code: err.code, idInactif: err.data?.id_chauffeur });
    } finally {
      setSaving(false);
    }
  };

  // Un CIN ou un permis appartient déjà à un chauffeur désactivé : on propose de le réactiver
  const reactiver = async () => {
    try {
      await api.reactiver(globalError.idInactif, form.date_expiration_permis || undefined);
      toast("Chauffeur réactivé.");
      navigate(`/chauffeurs/${globalError.idInactif}`);
    } catch (err) {
      setGlobalError({ message: err.message });
    }
  };

  if (loading) return <p className="muted">Chargement...</p>;

  const champ = (nom, label, props = {}, hint) => (
    <label className={`field ${errors[nom] ? "field-error" : ""}`}>
      <span>{label}</span>
      <input value={form[nom]} onChange={set(nom)} aria-invalid={Boolean(errors[nom])} {...props} />
      {errors[nom] ? <small className="error">{errors[nom]}</small> : hint && <small className="muted">{hint}</small>}
    </label>
  );

  return (
    <>
      <header className="page-head">
        <div>
          <h1>{edition ? "Modifier le chauffeur" : "Ajouter un chauffeur"}</h1>
          <p className="muted">{edition ? "Mettez à jour les informations puis enregistrez." : "Le chauffeur sera créé avec le statut Disponible."}</p>
        </div>
      </header>

      <form className="panel form" onSubmit={envoyer} noValidate>
        {globalError && (
          <div className="alert alert-bad">
            <p>{globalError.message}</p>
            {globalError.code === "CHAUFFEUR_INACTIF_EXISTANT" && (
              <button type="button" className="btn btn-sm" onClick={reactiver}>Réactiver ce chauffeur</button>
            )}
          </div>
        )}

        <div className="form-grid">
          {champ("prenom", "Prénom", { autoComplete: "given-name" })}
          {champ("nom", "Nom", { autoComplete: "family-name" })}
          {champ("cin", "CIN", { style: { textTransform: "uppercase" } }, "Exemple : AB123456")}
          {champ("telephone", "Téléphone (facultatif)", { inputMode: "tel" }, "Exemple : 0612345678")}
          {champ("numero_permis", "Numéro de permis")}
          <label className="field">
            <span>Catégorie de permis</span>
            <select value={form.categorie_permis} onChange={set("categorie_permis")}>
              {CATEGORIES.map((c) => <option key={c} value={c}>{c}</option>)}
            </select>
          </label>
          {champ("date_expiration_permis", "Date d'expiration du permis", { type: "date" })}

          {/* Sélection du véhicule issu du service véhicule */}
          <label className="field">
            <span>Véhicule assigné (service véhicule)</span>
            <select value={form.vehicule_id} onChange={set("vehicule_id")}>
              <option value="">— Aucun véhicule —</option>
              {vehicules.map((v) => (
                <option key={v.id} value={v.id}>
                  #{v.id} - {v.immatriculation || "Sans immatriculation"} ({v.marque || ""} {v.modele || ""} {v.statut ? `• ${v.statut}` : ""})
                </option>
              ))}
            </select>
          </label>
        </div>

        <div className="form-actions">
          <Link className="btn" to={edition ? `/chauffeurs/${id}` : "/chauffeurs"}>Annuler</Link>
          <button className="btn btn-primary" disabled={saving}>
            {saving ? "Enregistrement..." : edition ? "Enregistrer les modifications" : "Ajouter le chauffeur"}
          </button>
        </div>
      </form>
    </>
  );
}
