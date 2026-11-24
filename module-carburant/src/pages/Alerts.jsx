import { useEffect, useState } from "react";
import { TriangleAlert, Search } from "lucide-react";
import { Link } from "react-router-dom";
import { getAlerts } from "../services/api";
import { formatDate, formatNombre } from "../utils/formatters";
import { useAuth } from "../components/AuthContext";
import useCarburantReferences from "../hooks/useCarburantReferences";

export default function Alerts() {
  const { user } = useAuth();
  const chauffeur = user?.role === "CHAUFFEUR";
  const { vehicles, vehicleLabels, loading: referencesLoading, error: referencesError } = useCarburantReferences();
  const [alerts, setAlerts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [filters, setFilters] = useState({ vehicule_id: "", date_debut: "", date_fin: "" });

  const load = () => {
    setLoading(true);
    getAlerts(filters).then(setAlerts).catch(() => setAlerts([])).finally(() => setLoading(false));
  };
  useEffect(() => {
    load();
  }, []);

  return (
    <main className="page-content">
      <div className="info-banner"><TriangleAlert size={20} /><p>Une alerte apparaît lorsque la consommation dépasse de plus de <strong>30 %</strong> la moyenne des relevés précédents.</p></div>
      {referencesError && <div className="error-banner" role="alert">Impossible de charger les immatriculations : {referencesError}</div>}
      <form className="filter-bar compact" onSubmit={(event) => { event.preventDefault(); load(); }}>
        <label><span>Véhicule</span><select value={filters.vehicule_id} onChange={(event) => setFilters({ ...filters, vehicule_id: event.target.value })}><option value="">Tous</option>{vehicles.map((vehicle) => <option key={vehicle.id} value={vehicle.id}>{vehicle.immatriculation}</option>)}</select></label>
        <label><span>Du</span><input type="date" value={filters.date_debut} onChange={(event) => setFilters({ ...filters, date_debut: event.target.value })} /></label>
        <label><span>Au</span><input type="date" value={filters.date_fin} onChange={(event) => setFilters({ ...filters, date_fin: event.target.value })} /></label>
        <button className="primary-button filter-submit" type="submit"><Search size={16} /> Filtrer</button>
      </form>
      {loading ? <div className="alert-skeletons"><div /><div /></div> : alerts.length === 0 ? (
        <section className="card empty-state"><span className="empty-illustration success">✓</span><h3>Aucune alerte</h3><p>Les consommations sont actuellement normales.</p></section>
      ) : <div className="alerts-grid">{alerts.map((alert) => (
        <article className="card alert-card" key={alert.id}>
          <div className="alert-card-icon"><TriangleAlert size={21} /></div>
          <div className="alert-card-body"><span className="alert-kicker">{vehicleLabels.get(alert.vehicule_id) ?? (referencesLoading ? "Chargement…" : "Véhicule inconnu")}</span><strong>{formatNombre(alert.consommation_l_100km, 1)} <small>L/100 km</small></strong><p>Plein du {formatDate(alert.date_plein)} · {formatNombre(alert.quantite_litres, 1)} L · {formatNombre(alert.kilometrage, 0)} km</p><span className="alert-message">{alert.message_anomalie}</span></div>
          {!chauffeur && <Link className="secondary-button" to={`/modifier/${alert.id}`}>Vérifier</Link>}
        </article>
      ))}</div>}
    </main>
  );
}
