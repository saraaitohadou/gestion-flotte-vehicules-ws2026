import { BellOff, ChevronRight } from "lucide-react";
import { Link } from "react-router-dom";
import { formatDate, formatNombre } from "../utils/formatters";

export default function AlertsPanel({ alerts }) {
  return (
    <section className="card alerts-card" aria-labelledby="alerts-title">
      <div className="card-heading">
        <h2 id="alerts-title">Dernières alertes</h2>
        <Link to="/alertes" className="see-all">Voir tout <ChevronRight size={15} /></Link>
      </div>
      {alerts.length === 0 ? (
        <div className="empty-alerts" role="status">
          <span className="empty-icon"><BellOff size={24} /></span>
          <strong>Aucune alerte</strong>
        </div>
      ) : (
        <div className="alert-list">
          {alerts.slice(0, 3).map((alert) => (
            <article className="alert-item" key={alert.id}>
              <span className="alert-dot" />
              <div>
                <strong>Véhicule {alert.vehicule_id}</strong>
                <span>{formatDate(alert.date_plein)} · {formatNombre(alert.consommation_l_100km, 1)} L/100 km</span>
              </div>
            </article>
          ))}
        </div>
      )}
    </section>
  );
}
