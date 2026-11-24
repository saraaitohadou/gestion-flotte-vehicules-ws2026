import { useEffect, useMemo, useState } from "react";
import { Download, Pencil, Trash2, Search, ChevronLeft, ChevronRight } from "lucide-react";
import { Link } from "react-router-dom";
import { deleteFill, getFills } from "../services/api";
import { formatDate, formatMontant, formatNombre } from "../utils/formatters";
import StatusBadge from "../components/StatusBadge";
import Modal from "../components/Modal";
import Toast from "../components/Toast";
import { useAuth } from "../components/AuthContext";
import useCarburantReferences from "../hooks/useCarburantReferences";

const PAGE_SIZE = 20;

const csvValue = (value) => {
  let text = String(value ?? "");
  if (/^[=+\-@]/.test(text)) text = `'${text}`;
  return `"${text.replace(/"/g, '""')}"`;
};

export default function Fillups() {
  const { user } = useAuth();
  const chauffeur = user?.role === "CHAUFFEUR";
  const { vehicles, drivers, vehicleLabels, driverLabels, loading: referencesLoading, error: referencesError } = useCarburantReferences();
  const [fills, setFills] = useState([]);
  const [loading, setLoading] = useState(true);
  const [filters, setFilters] = useState({ vehicule_id: "", chauffeur_id: "", type_carburant: "", date_debut: "", date_fin: "" });
  const [page, setPage] = useState(0);
  const [sort, setSort] = useState({ key: "date_plein", direction: "desc" });
  const [toDelete, setToDelete] = useState(null);
  const [toast, setToast] = useState(null);

  const load = () => {
    setLoading(true);
    getFills({ ...filters, limit: 500 })
      .then(setFills)
      .catch((error) => setToast({ type: "error", message: error.message }))
      .finally(() => setLoading(false));
  };
  useEffect(load, []);

  const labelVehicule = (id) => vehicleLabels.get(id) ?? (referencesLoading ? "Chargement…" : "Véhicule inconnu");
  const labelChauffeur = (id) => id == null ? "—" : driverLabels.get(id) ?? (referencesLoading ? "Chargement…" : "Chauffeur inconnu");
  const sorted = useMemo(() => fills.slice().sort((a, b) => {
    const left = a[sort.key] ?? "";
    const right = b[sort.key] ?? "";
    const result = typeof left === "string" ? left.localeCompare(right, "fr") : left - right;
    return sort.direction === "asc" ? result : -result;
  }), [fills, sort]);
  const pageCount = Math.ceil(sorted.length / PAGE_SIZE);
  const visible = sorted.slice(page * PAGE_SIZE, (page + 1) * PAGE_SIZE);

  const changeSort = (key) => setSort((current) => ({ key, direction: current.key === key && current.direction === "asc" ? "desc" : "asc" }));
  const submitFilters = (event) => {
    event.preventDefault();
    setPage(0);
    load();
  };
  const resetFilters = () => {
    setFilters({ vehicule_id: "", chauffeur_id: "", type_carburant: "", date_debut: "", date_fin: "" });
    setPage(0);
  };
  const exportCsv = () => {
    const lines = [
      ["Date","Véhicule","Chauffeur","Carburant","Litres","Prix/L","Montant (MAD)","Km","Conso","État"],
      ...sorted.map((fill) => [fill.date_plein, labelVehicule(fill.vehicule_id), labelChauffeur(fill.chauffeur_id), fill.type_carburant, fill.quantite_litres, fill.prix_litre, fill.montant_total, fill.kilometrage, fill.consommation_l_100km ?? "", fill.anomalie ? "Alerte" : "Normal"]),
    ];
    const blob = new Blob(["\uFEFF" + lines.map((line) => line.map(csvValue).join(";")).join("\r\n")], { type: "text/csv;charset=utf-8" });
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = "pleins-carburant.csv";
    link.click();
    URL.revokeObjectURL(link.href);
  };
  const confirmDelete = async () => {
    try {
      await deleteFill(toDelete.id);
      setToDelete(null);
      setToast({ type: "success", message: "Plein supprimé." });
      load();
    } catch (error) {
      setToast({ type: "error", message: error.message });
    }
  };

  return (
    <main className="page-content">
      {referencesError && <div className="error-banner" role="alert">Impossible de charger les immatriculations ou les chauffeurs : {referencesError}</div>}
      <form className="filter-bar" onSubmit={submitFilters}>
        <label><span>Véhicule</span><select value={filters.vehicule_id} onChange={(event) => setFilters({ ...filters, vehicule_id: event.target.value })}><option value="">Tous</option>{vehicles.map((vehicle) => <option key={vehicle.id} value={vehicle.id}>{vehicle.immatriculation}</option>)}</select></label>
        {!chauffeur && <label><span>Chauffeur</span><select value={filters.chauffeur_id} onChange={(event) => setFilters({ ...filters, chauffeur_id: event.target.value })}><option value="">Tous</option>{drivers.map((driver) => <option key={driver.id_chauffeur} value={driver.id_chauffeur}>{driver.nom} {driver.prenom}</option>)}</select></label>}
        <label><span>Carburant</span><select value={filters.type_carburant} onChange={(event) => setFilters({ ...filters, type_carburant: event.target.value })}><option value="">Tous</option>{["diesel","essence","gpl"].map((type) => <option key={type} value={type}>{type[0].toUpperCase() + type.slice(1)}</option>)}</select></label>
        <label><span>Du</span><input type="date" value={filters.date_debut} onChange={(event) => setFilters({ ...filters, date_debut: event.target.value })} /></label>
        <label><span>Au</span><input type="date" value={filters.date_fin} onChange={(event) => setFilters({ ...filters, date_fin: event.target.value })} /></label>
        <button className="secondary-button" type="button" onClick={resetFilters}>Réinitialiser</button>
        <button className="primary-button filter-submit" type="submit"><Search size={16} /> Filtrer</button>
      </form>
      <section className="card table-card">
        <div className="card-heading table-heading">
          <div><h2>Liste des pleins</h2><span className="count-label">{fills.length} résultat{fills.length > 1 ? "s" : ""}</span></div>
          {!chauffeur && <button className="secondary-button" type="button" onClick={exportCsv}><Download size={16} /> Exporter CSV</button>}
        </div>
        {loading ? <div className="skeleton-table" aria-label="Chargement"><div /><div /><div /><div /><div /></div> : fills.length === 0 ? (
          <div className="empty-state"><span className="empty-illustration">⛽</span><h3>Aucun plein trouvé</h3><p>Modifiez les filtres{!chauffeur ? " ou créez un nouveau plein" : ""}.</p>{!chauffeur && <Link className="primary-button" to="/nouveau">Nouveau plein</Link>}</div>
        ) : (
          <div className="table-scroll">
            <table className="data-table" aria-label="Liste des pleins">
              <thead><tr>
                {[["date_plein","Date"],["vehicule_id","Véhicule"],...(!chauffeur ? [["chauffeur_id","Chauffeur"]] : []),["type_carburant","Carburant"],["quantite_litres","Litres"],["prix_litre","Prix/L"],["montant_total","Montant"],["kilometrage","Km"],["consommation_l_100km","Conso"]].map(([key,label]) => (
                  <th scope="col" key={key}><button type="button" onClick={() => changeSort(key)} aria-label={`Trier par ${label}`}>{label}{sort.key === key ? (sort.direction === "asc" ? " ↑" : " ↓") : ""}</button></th>
                ))}
                <th scope="col">État</th>{!chauffeur && <th scope="col" className="actions-column">Actions</th>}
              </tr></thead>
              <tbody>{visible.map((fill) => (
                <tr key={fill.id}>
                  <td>{formatDate(fill.date_plein)}</td><td>{labelVehicule(fill.vehicule_id)}</td>{!chauffeur && <td>{labelChauffeur(fill.chauffeur_id)}</td>}
                  <td><span className={`fuel-badge fuel-${fill.type_carburant}`}>{fill.type_carburant}</span></td>
                  <td className="numeric">{formatNombre(fill.quantite_litres, 1)} L</td><td className="numeric">{formatMontant(fill.prix_litre)}</td><td className="numeric">{formatMontant(fill.montant_total)}</td>
                  <td className="numeric">{formatNombre(fill.kilometrage, 0)} km</td><td className="numeric">{fill.consommation_l_100km == null ? "—" : `${formatNombre(fill.consommation_l_100km, 1)} L/100 km`}</td>
                  <td><StatusBadge anomalie={fill.anomalie} /></td>
                  {!chauffeur && <td><div className="row-actions"><Link className="icon-button" to={`/modifier/${fill.id}`} aria-label={`Modifier le plein du ${formatDate(fill.date_plein)}`}><Pencil size={16} /></Link><button className="icon-button danger-icon" type="button" onClick={() => setToDelete(fill)} aria-label="Supprimer le plein"><Trash2 size={16} /></button></div></td>}
                </tr>
              ))}</tbody>
            </table>
          </div>
        )}
        {!loading && fills.length > 0 && <div className="pagination"><span>Page {page + 1} sur {Math.max(pageCount, 1)}</span><div><button className="icon-button" type="button" disabled={page === 0} onClick={() => setPage(page - 1)} aria-label="Page précédente"><ChevronLeft size={18} /></button><button className="icon-button" type="button" disabled={page + 1 >= pageCount} onClick={() => setPage(page + 1)} aria-label="Page suivante"><ChevronRight size={18} /></button></div></div>}
      </section>
      <Modal open={Boolean(toDelete)} title="Supprimer ce plein ?" onClose={() => setToDelete(null)}><p className="modal-copy">Cette action est définitive. La consommation et les alertes du véhicule seront recalculées.</p><div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setToDelete(null)}>Annuler</button><button className="danger-button" type="button" onClick={confirmDelete}>Supprimer</button></div></Modal>
      <Toast toast={toast} onClose={() => setToast(null)} />
    </main>
  );
}
