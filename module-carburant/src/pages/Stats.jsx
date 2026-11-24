import { useEffect, useState } from "react";
import { BarChart3, Search } from "lucide-react";
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import { getFills, getStats } from "../services/api";
import { formatMontant, formatNombre } from "../utils/formatters";

export default function Stats() {
  const [vehicles, setVehicles] = useState([]);
  const [filters, setFilters] = useState({ vehicule_id: "", date_debut: "", date_fin: "" });
  const [stats, setStats] = useState(null);
  const [fills, setFills] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    getFills({ limit: 500 }).then((list) => setVehicles([...new Set(list.map((fill) => fill.vehicule_id))].sort((a,b) => a-b))).catch(() => {});
  }, []);

  const calculate = async (event) => {
    event?.preventDefault();
    if (!filters.vehicule_id) return;
    setLoading(true);
    setError("");
    try {
      const [result, list] = await Promise.all([
        getStats(filters.vehicule_id, { date_debut: filters.date_debut, date_fin: filters.date_fin }),
        getFills({ vehicule_id: filters.vehicule_id, date_debut: filters.date_debut, date_fin: filters.date_fin, limit: 500 }),
      ]);
      setStats(result);
      setFills(list.slice().reverse());
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
    ["Coût au kilomètre", stats.cout_au_km == null ? "—" : `${formatNombre(stats.cout_au_km, 3)} MAD/km`],
  ] : [];

  return (
    <main className="page-content">
      <form className="filter-bar compact" onSubmit={calculate}>
        <label><span>Véhicule *</span><select required value={filters.vehicule_id} onChange={(event) => setFilters({ ...filters, vehicule_id: event.target.value })}><option value="">Sélectionner</option>{vehicles.map((id) => <option key={id} value={id}>Véhicule {id}</option>)}</select></label>
        <label><span>Du</span><input type="date" value={filters.date_debut} onChange={(event) => setFilters({ ...filters, date_debut: event.target.value })} /></label>
        <label><span>Au</span><input type="date" value={filters.date_fin} onChange={(event) => setFilters({ ...filters, date_fin: event.target.value })} /></label>
        <button className="primary-button filter-submit" type="submit" disabled={!filters.vehicule_id || loading}>{loading ? "Calcul…" : "Calculer"}</button>
      </form>
      {error && <div className="error-banner" role="alert">{error}</div>}
      {!stats ? <section className="card empty-state"><span className="empty-illustration"><BarChart3 /></span><h3>Choisissez un véhicule</h3><p>Sélectionnez un véhicule et une période pour afficher les statistiques.</p></section> : (
        <>
          <section className="kpi-grid stats-grid">{kpis.map(([label,value]) => <article className="kpi-card" key={label}><span className="kpi-label">{label}</span><strong className="kpi-value">{value}</strong></article>)}</section>
          {fills.length > 0 && <section className="card chart-card"><div className="card-heading"><h2>Consommation par plein (L/100 km)</h2></div><div className="chart-wrap"><ResponsiveContainer width="100%" height="100%"><BarChart data={fills.map((fill) => ({ date: fill.date_plein?.slice(5).replace("-", "/"), value: fill.consommation_l_100km }))} margin={{ top: 20, right: 8, left: -18, bottom: 0 }}><CartesianGrid stroke="var(--border)" vertical={false} /><XAxis dataKey="date" axisLine={false} tickLine={false} /><YAxis axisLine={false} tickLine={false} /><Tooltip /><Bar dataKey="value" fill="var(--blue-500)" radius={[7,7,0,0]} /></BarChart></ResponsiveContainer></div></section>}
        </>
      )}
    </main>
  );
}
