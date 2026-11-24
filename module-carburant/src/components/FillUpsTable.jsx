import { Plus } from "lucide-react";
import { Link } from "react-router-dom";
import StatusBadge from "./StatusBadge";
import { formatDate, formatMontant, formatNombre } from "../utils/formatters";
import { useAuth } from "./AuthContext";
import useCarburantReferences from "../hooks/useCarburantReferences";

export default function FillUpsTable({ fills }) {
  const { user } = useAuth();
  const chauffeur = user?.role === "CHAUFFEUR";
  const { vehicleLabels, driverLabels, loading: referencesLoading, error: referencesError } = useCarburantReferences();
  const labelVehicule = (id) => vehicleLabels.get(id) ?? (referencesLoading ? "Chargement…" : "Véhicule inconnu");
  const labelChauffeur = (id) => id == null ? "—" : driverLabels.get(id) ?? (referencesLoading ? "Chargement…" : "Chauffeur inconnu");
  return (
    <section className="card table-card" aria-labelledby="fills-title">
      <div className="card-heading table-heading">
        <h2 id="fills-title">Derniers pleins</h2>
        {!chauffeur && <Link to="/nouveau" className="primary-button"><Plus size={17} /> Nouveau plein</Link>}
      </div>
      {referencesError && <div className="error-banner" role="alert">Impossible de charger les libellés des véhicules ou chauffeurs : {referencesError}</div>}
      {fills.length === 0 ? (
        <div className="table-empty">Aucun plein enregistré pour le moment.</div>
      ) : (
        <div className="table-scroll">
          <table>
            <thead>
              <tr>
                {["Date","Véhicule","Chauffeur","Carburant","Litres","Prix/L","Montant (MAD)","Km","Conso","État"].map((label) => <th scope="col" key={label}>{label}</th>)}
              </tr>
            </thead>
            <tbody>
              {fills.slice(0, 5).map((fill) => (
                <tr key={fill.id}>
                  <td>{formatDate(fill.date_plein)}</td>
                  <td>{labelVehicule(fill.vehicule_id)}</td>
                  <td>{labelChauffeur(fill.chauffeur_id)}</td>
                  <td>{fill.type_carburant || "—"}</td>
                  <td>{formatNombre(fill.quantite_litres, 1)} L</td>
                  <td>{formatMontant(fill.prix_litre)}</td>
                  <td>{formatMontant(fill.montant_total)}</td>
                  <td>{formatNombre(fill.kilometrage, 0)} km</td>
                  <td>{fill.consommation_l_100km == null ? "—" : `${formatNombre(fill.consommation_l_100km, 1)} L/100 km`}</td>
                  <td><StatusBadge anomalie={fill.anomalie} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}
