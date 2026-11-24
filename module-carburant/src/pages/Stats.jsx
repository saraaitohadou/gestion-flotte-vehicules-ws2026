import { useState } from "react";
import { BarChart3, Search } from "lucide-react";
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import { getFills, getLatestMaintenance, getStats } from "../services/api";
import { formatMontant, formatNombre } from "../utils/formatters";
import useCarburantReferences from "../hooks/useCarburantReferences";

export default function Stats() {
  const { vehicles, error: referencesError } = useCarburantReferences();
  const [filters, setFilters] = useState({ vehicule_id: "", date_debut: "", date_fin: "" });
  const [stats, setStats] = useState(null);
  const [fills, setFills] = useState([]);
  const [maintenance, setMaintenance] = useState(null);
  const [maintenanceError, setMaintenanceError] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  const calculate = async (event) => {
    event?.preventDefault();
    if (!filters.vehicule_id) return;
    setLoading(true);
    setError("");
    setMaintenance(null);
    setMaintenanceError("");
    try {
      const maintenanceRequest = getLatestMaintenance(filters.vehicule_id)
        .then((data) => ({ data, error: "" }))
        .catch((requestError) => ({ data: null, error: requestError.message }));
      const [result, list, latestMaintenance] = await Promise.all([
        getStats(filters.vehicule_id, { date_debut: filters.date_debut, date_fin: filters.date_fin }),
        getFills({ vehicule_id: filters.vehicule_id, date_debut: filters.date_debut, date_fin: filters.date_fin, limit: 500 }),
        maintenanceRequest,
      ]);
      setStats(result);
      setFills(list.slice().reverse());
      setMaintenance(latestMaintenance.data);
      setMaintenanceError(latestMaintenance.error);
    } catch (requestError) {
      setError(requestError.message);
      setStats(null);
    } finally {
      setLoading(false);
    }
  };

  const kpis = stats ? [
    ["Nombre de pleins", stats.nombre_pleins],
    ["Total de litres", `${formatNombre(stats.total_litres, 1)} L`],
    ["Coût total", formatMontant(stats.cout_total)],
    ["Distance parcourue", `${formatNombre(stats.distance_km, 0)} km`],
    ["Consommation moyenne", stats.consommation_l_100km == null ? "—" : `${formatNombre(stats.consommation_l_100km, 1)} L/100 km`],
  ] : [];

  return (
    <main className="page-content">
      <form className="filter-bar compact" onSubmit={calculate}>
        <label><span>Véhicule *</span><select required value={filters.vehicule_id} onChange={(event) => setFilters({ ...filters, vehicule_id: event.target.value })}><option value="">Sélectionner</option>{vehicles.map((vehicle) => <option key={vehicle.id} value={vehicle.id}>{vehicle.immatriculation}</option>)}</select></label>
        <label><span>Du</span><input type="date" value={filters.date_debut} onChange={(event) => setFilters({ ...filters, date_debut: event.target.value })} /></label>
        <label><span>Au</span><input type="date" value={filters.date_fin} onChange={(event) => setFilters({ ...filters, date_fin: event.target.value })} /></label>
        <button className="primary-button filter-submit" type="submit" disabled={!filters.vehicule_id || loading}>{loading ? "Calcul…" : "Calculer"}</button>
      </form>
      {referencesError && <div className="error-banner" role="alert">Impossible de charger les immatriculations : {referencesError}</div>}
      {error && <div className="error-banner" role="alert">{error}</div>}
      {!stats ? <section className="card empty-state"><span className="empty-illustration"><BarChart3 /></span><h3>Choisissez un véhicule</h3><p>Sélectionnez un véhicule et une période pour afficher les statistiques.</p></section> : (
        <>
          <section className="kpi-grid stats-grid">{kpis.map(([label,value]) => <article className="kpi-card" key={label}><span className="kpi-label">{label}</span><strong className="kpi-value">{value}</strong></article>)}</section>
          <section className="card maintenance-card">
            <div className="card-heading"><h2>Dernier entretien réalisé</h2></div>
            <div className="maintenance-summary">
              {maintenanceError ? <p className="error-banner" role="alert">Entretien indisponible : {maintenanceError}</p> : maintenance ? (
                <p><strong>{maintenance.type}</strong>{maintenance.categorie ? ` — ${maintenance.categorie}` : ""} · {new Date(`${maintenance.date_entretien}T00:00:00`).toLocaleDateString("fr-FR")}</p>
              ) : <p>Aucun entretien terminé trouvé pour ce véhicule.</p>}
            </div>
          </section>
          {fills.length > 0 && <section className="card chart-card"><div className="card-heading"><h2>Consommation par plein (L/100 km)</h2></div><div className="chart-wrap"><ResponsiveContainer width="100%" height="100%"><BarChart data={fills.map((fill) => ({ date: fill.date_plein?.slice(5).replace("-", "/"), value: fill.consommation_l_100km }))} margin={{ top: 20, right: 8, left: -18, bottom: 0 }}><CartesianGrid stroke="var(--border)" vertical={false} /><XAxis dataKey="date" axisLine={false} tickLine={false} /><YAxis axisLine={false} tickLine={false} /><Tooltip /><Bar dataKey="value" fill="var(--blue-500)" radius={[7,7,0,0]} /></BarChart></ResponsiveContainer></div></section>}
        </>
      )}
    </main>
  );
}
