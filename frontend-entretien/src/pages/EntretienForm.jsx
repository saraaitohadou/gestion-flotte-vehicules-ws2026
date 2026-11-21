import { useEffect, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { api } from "../api.js";
import { useToast } from "../components/Toast.jsx";
import { CATEGORIES, TYPES } from "../utils.js";

const VIDE = { vehicule_id: "", type: "PREVENTIF", categorie: "VIDANGE", description: "", date_entretien: "", kilometrage: "", cout: "" };

export default function EntretienForm() {
  const { id } = useParams();
  const edition = Boolean(id);
  const navigate = useNavigate();
  const toast = useToast();

  const [form, setForm] = useState(VIDE);
  const [statut, setStatut] = useState(null);
  const [archive, setArchive] = useState(false);
  const [errors, setErrors] = useState({});
  const [globalError, setGlobalError] = useState("");
  const [loading, setLoading] = useState(edition);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!edition) return;
    (async () => {
      try {
        const { data } = await api.get(id);
        setForm({
          vehicule_id: data.vehicule_id, type: data.type, categorie: data.categorie,
          description: data.description ?? "", date_entretien: data.date_entretien ?? "",
          kilometrage: data.kilometrage ?? "", cout: data.cout ?? "",
        });
        setStatut(data.statut);
        setArchive(data.archive);
      } catch (e) {
        setGlobalError(e.message);
      } finally {
        setLoading(false);
      }
    })();
  }, [id, edition]);

  const modifiable = !edition || (!archive && ["PLANIFIE", "EN_COURS"].includes(statut));

  const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));
  const changerType = (t) => setForm((f) => ({ ...f, type: t, categorie: CATEGORIES[t][0] }));

  const valider = () => {
    const e = {};
    if (!form.vehicule_id) e.vehicule_id = "Vehicule obligatoire.";
    if (!form.description.trim()) e.description = "Description obligatoire.";
    if (!form.date_entretien) e.date_entretien = "Date obligatoire.";
    if (form.kilometrage === "" || Number(form.kilometrage) < 0) e.kilometrage = "Kilometrage obligatoire (>= 0).";
    if (form.cout === "" || Number(form.cout) < 0) e.cout = "Cout obligatoire (>= 0).";
    return e;
  };

  const envoyer = async (ev) => {
    ev.preventDefault();
    setGlobalError("");
    const e = valider();
    setErrors(e);
    if (Object.keys(e).length) return;

    const body = {
      ...form,
      vehicule_id: Number(form.vehicule_id),
      kilometrage: Number(form.kilometrage),
      cout: Number(form.cout),
    };
    setSaving(true);
    try {
      const res = edition ? await api.update(id, body) : await api.create(body);
      toast(edition ? "Entretien modifie." : "Entretien cree.");
      navigate(`/entretien/${res.data.id}`);
    } catch (err) {
      if (err.fields) setErrors(err.fields);
      setGlobalError(err.code ? `${err.message} (${err.code})` : err.message);
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <p className="muted">Chargement...</p>;

  const champ = (nom, label, input) => (
    <label className="field">
      <span>{label}</span>
      {input}
      {errors[nom] && <span className="error small">{errors[nom]}</span>}
    </label>
  );

  return (
    <>
      <div className="page-head">
        <div>
          <Link className="back" to={edition ? `/entretien/${id}` : "/entretien/liste"}>&larr; Retour</Link>
          <h1>{edition ? `Modifier l'entretien #${id}` : "Nouvel entretien"}</h1>
        </div>
      </div>

      {!modifiable && (
        <p className="alert alert-bad" role="alert">
          Cet entretien ne peut plus etre modifie (statut {statut}{archive ? ", archive" : ""}).
        </p>
      )}
      {globalError && <p className="alert alert-bad" role="alert">{globalError}</p>}

      <form className="panel" onSubmit={envoyer} noValidate>
        <fieldset disabled={!modifiable || saving} style={{ border: 0, padding: 0, margin: 0 }}>
          <div className="grid-2">
            {champ("vehicule_id", "Vehicule (id)",
              <input type="number" min="1" value={form.vehicule_id} onChange={(e) => set("vehicule_id", e.target.value)} disabled={edition} />)}
            {champ("type", "Type",
              <select value={form.type} onChange={(e) => changerType(e.target.value)}>
                {Object.entries(TYPES).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
              </select>)}
            {champ("categorie", "Categorie",
              <select value={form.categorie} onChange={(e) => set("categorie", e.target.value)}>
                {CATEGORIES[form.type].map((c) => <option key={c} value={c}>{c}</option>)}
              </select>)}
            {champ("date_entretien", "Date",
              <input type="date" value={form.date_entretien} onChange={(e) => set("date_entretien", e.target.value)} />)}
            {champ("kilometrage", "Kilometrage",
              <input type="number" min="0" value={form.kilometrage} onChange={(e) => set("kilometrage", e.target.value)} />)}
            {champ("cout", "Cout (MAD)",
              <input type="number" min="0" step="0.01" value={form.cout} onChange={(e) => set("cout", e.target.value)} />)}
          </div>
          {champ("description", "Description",
            <textarea rows="3" value={form.description} onChange={(e) => set("description", e.target.value)} />)}

          <div style={{ display: "flex", gap: 12, marginTop: 8 }}>
            <button className="btn btn-primary" disabled={!modifiable || saving}>
              {saving ? "Enregistrement..." : edition ? "Enregistrer" : "Creer l'entretien"}
            </button>
            <Link className="btn" to="/entretien/liste">Annuler</Link>
          </div>
        </fieldset>
      </form>
    </>
  );
}