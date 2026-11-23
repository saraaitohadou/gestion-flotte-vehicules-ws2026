import { useEffect, useMemo, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { createFill, getFill, getFills, updateFill } from "../services/api";
import Toast from "../components/Toast";

export default function FillupForm() {
  const { id } = useParams();
  const navigate = useNavigate();
  const editing = Boolean(id);
  const [references, setReferences] = useState({ vehicles: [], drivers: [] });
  const [sending, setSending] = useState(false);
  const [loading, setLoading] = useState(editing);
  const [toast, setToast] = useState(null);
  const [errors, setErrors] = useState({});
  const [form, setForm] = useState({
    vehicule_id: "", chauffeur_id: "", date_plein: new Date().toISOString().slice(0, 10),
    station: "", type_carburant: "diesel", quantite_litres: "", prix_litre: "",
    kilometrage: "", plein_complet: true,
  });

  useEffect(() => {
    getFills({ limit: 500 }).then((fills) => setReferences({
      vehicles: [...new Set(fills.map((fill) => fill.vehicule_id))].sort((a,b) => a-b),
      drivers: [...new Set(fills.map((fill) => fill.chauffeur_id).filter((value) => value != null))].sort((a,b) => a-b),
    })).catch(() => {});
    if (editing) getFill(id).then((fill) => setForm({
      vehicule_id: fill.vehicule_id, chauffeur_id: fill.chauffeur_id ?? "",
      date_plein: fill.date_plein, station: fill.station ?? "", type_carburant: fill.type_carburant,
      quantite_litres: fill.quantite_litres, prix_litre: fill.prix_litre,
      kilometrage: fill.kilometrage, plein_complet: fill.plein_complet,
    })).catch((error) => setToast({ type: "error", message: error.message })).finally(() => setLoading(false));
  }, [id, editing]);

  const amount = useMemo(() => {
    const quantity = Number(form.quantite_litres);
    const price = Number(form.prix_litre);
    return quantity > 0 && price > 0 ? new Intl.NumberFormat("fr-MA", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(quantity * price) + " MAD" : "—";
  }, [form.quantite_litres, form.prix_litre]);

  const setField = (name, value) => {
    setForm((current) => ({ ...current, [name]: value }));
    setErrors((current) => ({ ...current, [name]: undefined }));
  };
  const handleSubmit = async (event) => {
    event.preventDefault();
    const nextErrors = {};
    if (!form.vehicule_id) nextErrors.vehicule_id = "Sélectionnez un véhicule.";
    if (!form.date_plein) nextErrors.date_plein = "La date est obligatoire.";
    if (!(Number(form.quantite_litres) > 0)) nextErrors.quantite_litres = "La quantité doit être strictement supérieure à 0.";
    if (!(Number(form.prix_litre) > 0)) nextErrors.prix_litre = "Le prix doit être strictement supérieur à 0.";
    if (!(Number(form.kilometrage) >= 0)) nextErrors.kilometrage = "Le kilométrage doit être positif.";
    setErrors(nextErrors);
    if (Object.keys(nextErrors).length) return;
    setSending(true);
    const body = {
      vehicule_id: Number(form.vehicule_id),
      chauffeur_id: form.chauffeur_id ? Number(form.chauffeur_id) : null,
      date_plein: form.date_plein,
      station: form.station || null,
      type_carburant: form.type_carburant,
      quantite_litres: Number(form.quantite_litres),
      prix_litre: Number(form.prix_litre),
      kilometrage: Number(form.kilometrage),
      plein_complet: form.plein_complet,
    };
    try {
      const result = editing ? await updateFill(id, body) : await createFill(body);
      setToast({ type: "success", message: `Plein ${editing ? "modifié" : "enregistré"} : ${amount}${result.anomalie ? " — surconsommation détectée." : ""}` });
      setTimeout(() => navigate("/pleins"), 700);
    } catch (error) {
      setToast({ type: "error", message: error.message });
    } finally {
      setSending(false);
    }
  };

  if (loading) return <main className="page-content"><div className="form-skeleton"><div /><div /><div /><div /></div></main>;
  return (
    <main className="page-content">
      <form className="form-layout" onSubmit={handleSubmit} noValidate>
        <section className="card form-card">
          <div className="card-heading"><h2>Informations du plein</h2><span className="required-note">* Champs obligatoires</span></div>
          <div className="form-grid">
            <label className="field"><span>Véhicule *</span><select value={form.vehicule_id} onChange={(event) => setField("vehicule_id", event.target.value)} aria-invalid={Boolean(errors.vehicule_id)}><option value="">Sélectionner</option>{references.vehicles.map((vehicle) => <option key={vehicle} value={vehicle}>Véhicule {vehicle}</option>)}</select>{errors.vehicule_id && <small className="field-error">{errors.vehicule_id}</small>}</label>
            <label className="field"><span>Chauffeur</span><select value={form.chauffeur_id} onChange={(event) => setField("chauffeur_id", event.target.value)}><option value="">Aucun</option>{references.drivers.map((driver) => <option key={driver} value={driver}>Chauffeur {driver}</option>)}</select></label>
            <label className="field"><span>Date du plein *</span><input type="date" value={form.date_plein} onChange={(event) => setField("date_plein", event.target.value)} aria-invalid={Boolean(errors.date_plein)} />{errors.date_plein && <small className="field-error">{errors.date_plein}</small>}</label>
            <label className="field"><span>Station</span><input type="text" maxLength="100" value={form.station} onChange={(event) => setField("station", event.target.value)} placeholder="Nom de la station" /></label>
          </div>
          <fieldset className="fuel-fieldset"><legend>Type de carburant *</legend><div className="fuel-options">{["diesel","essence","gpl"].map((type) => <label key={type} className={form.type_carburant === type ? "selected" : ""}><input type="radio" name="type" value={type} checked={form.type_carburant === type} onChange={(event) => setField("type_carburant", event.target.value)} /><span>{type[0].toUpperCase() + type.slice(1)}</span></label>)}</div></fieldset>
          <div className="form-grid">
            <label className="field"><span>Quantité (litres) *</span><input type="number" min="0.01" step="0.01" value={form.quantite_litres} onChange={(event) => setField("quantite_litres", event.target.value)} aria-invalid={Boolean(errors.quantite_litres)} />{errors.quantite_litres && <small className="field-error">{errors.quantite_litres}</small>}</label>
            <label className="field"><span>Prix par litre (MAD) *</span><input type="number" min="0.01" step="0.01" value={form.prix_litre} onChange={(event) => setField("prix_litre", event.target.value)} aria-invalid={Boolean(errors.prix_litre)} />{errors.prix_litre && <small className="field-error">{errors.prix_litre}</small>}</label>
            <label className="field"><span>Kilométrage (km) *</span><input type="number" min="0" step="1" value={form.kilometrage} onChange={(event) => setField("kilometrage", event.target.value)} aria-invalid={Boolean(errors.kilometrage)} />{errors.kilometrage && <small className="field-error">{errors.kilometrage}</small>}</label>
          </div>
          <label className="check-field"><input type="checkbox" checked={form.plein_complet} onChange={(event) => setField("plein_complet", event.target.checked)} /><span><strong>Plein complet</strong><small>Nécessaire pour calculer la consommation</small></span></label>
          <div className="form-actions"><button className="secondary-button" type="button" onClick={() => navigate("/pleins")}>Annuler</button><button className="primary-button" type="submit" disabled={sending}>{sending ? "Enregistrement…" : editing ? "Enregistrer les modifications" : "Enregistrer le plein"}</button></div>
        </section>
        <aside className="card summary-card"><span className="summary-label">Montant calculé</span><strong>{amount}</strong><p>Le montant est calculé automatiquement. La consommation et les alertes sont recalculées par le service.</p></aside>
      </form>
      <Toast toast={toast} onClose={() => setToast(null)} />
    </main>
  );
}
