import { useEffect, useMemo, useState } from "react";
import { TriangleAlert, Search } from "lucide-react";
import { Link } from "react-router-dom";
import { getAlerts, getFills } from "../services/api";
import { formatDate, formatMontant, formatNombre } from "../utils/formatters";
import { useAuth } from "../components/AuthContext";

export default function Alerts() {
  const { user } = useAuth();
  const chauffeur = user?.role === "CHAUFFEUR";
  const [alerts, setAlerts] = useState([]);
  const [vehicles, setVehicles] = useState([]);
  const [loading, setLoading] = useState(true);
  const [filters, setFilters] = useState({ vehicule_id: "", date_debut: "", date_fin: "" });

  const load = () => {
    setLoading(true);
    getAlerts(filters).then(setAlerts).catch(() => setAlerts([])).finally(() => setLoading(false));
  };
  useEffect(() => {
    getFills({ limit: 500 }).then((fills) => setVehicles([...new Set(fills.map((fill) => fill.vehicule_id))].sort((a,b) => a-b))).catch(() => {});
    load();
  }, []);

  return (
    <main className="page-content">
      <div className="info-banner"><TriangleAlert size={20} /><p>Une alerte apparaît lorsque la consommation dépasse de plus de <strong>30 %</strong> la moyenne des relevés précédents.</p></div>
      <form className="filter-bar compact" onSubmit={(event) => { event.preventDefault(); load(); }}>
        <label><span>Véhicule</span><select value={filters.vehicule_id} onChange={(event) => setFilters({ ...filters, vehicule_id: event.target.value })}><option value="">Tous</option>{vehicles.map((id) => <option key={id} value={id}>Véhicule {id}</option>)}</select></label>
        <label><span>Du</span><input type="date" value={filters.date_debut} onChange={(event) => setFilters({ ...filters, date_debut: event.target.value })} /></label>
        <label><span>Au</span><input type="date" value={filters.date_fin} onChange={(event) => setFilters({ ...filters, date_fin: event.target.value })} /></label>
        <button className="primary-button filter-submit" type="submit"><Search size={16} /> Filtrer</button>
      </form>
      {loading ? <div className="alert-skeletons"><div /><div /></div> : alerts.length === 0 ? (
        <section className="card empty-state"><span className="empty-illustration success">✓</span><h3>Aucune alerte</h3><p>Les consommations sont actuellement normales.</p></section>
      ) : <div className="alerts-grid">{alerts.map((alert) => (
        <article className="card alert-card" key={alert.id}>
          <div className="alert-card-icon"><TriangleAlert size={21} /></div>
          <div className="alert-card-body"><span className="alert-kicker">Véhicule {alert.vehicule_id}</span><strong>{formatNombre(alert.consommation_l_100km, 1)} <small>L/100 km</small></strong><p>Plein du {formatDate(alert.date_plein)} · {formatNombre(alert.quantite_litres, 1)} L · {formatNombre(alert.kilometrage, 0)} km</p><span className="alert-message">{alert.message_anomalie}</span></div>
          {!chauffeur && <Link className="secondary-button" to={`/modifier/${alert.id}`}>Vérifier</Link>}
        </article>
      ))}</div>}
    </main>
  );
}
