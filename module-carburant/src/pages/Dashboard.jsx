import { useEffect, useMemo, useState } from "react";
import { CircleDollarSign, Droplets, Gauge, Route } from "lucide-react";
import KpiCard from "../components/KpiCard";
import FuelCostChart from "../components/FuelCostChart";
import AlertsPanel from "../components/AlertsPanel";
import FillUpsTable from "../components/FillUpsTable";
import { getDashboardData } from "../services/api";
import { formatMontant, formatNombre } from "../utils/formatters";

const MOCK_FILLS = [
  { id: 1, date_plein: "2026-11-13", vehicule_id: 1, chauffeur_id: 1, type_carburant: "essence", quantite_litres: 20, prix_litre: 300, montant_total: 6000, kilometrage: 20, consommation_l_100km: null, anomalie: false },
];

export default function Dashboard() {
  const [fills, setFills] = useState(MOCK_FILLS);
  const [alerts, setAlerts] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    getDashboardData()
      .then((data) => {
        setFills(data.pleins);
        setAlerts(data.alertes);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  const now = new Date();
  const monthKey = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}`;
  const monthFills = fills.filter((fill) => fill.date_plein?.startsWith(monthKey));
  const monthlyCost = monthFills.reduce((sum, fill) => sum + (fill.montant_total || 0), 0);
  const monthlyLitres = monthFills.reduce((sum, fill) => sum + (fill.quantite_litres || 0), 0);
  const consumptions = monthFills.map((fill) => fill.consommation_l_100km).filter((value) => value != null);
  const averageConsumption = consumptions.length ? consumptions.reduce((sum, value) => sum + value, 0) / consumptions.length : null;
  const costPerKm = useMemo(() => {
    const fillsByVehicle = new Map();
    fills.forEach((fill) => {
      const vehicleFills = fillsByVehicle.get(fill.vehicule_id) || [];
      vehicleFills.push(fill);
      fillsByVehicle.set(fill.vehicule_id, vehicleFills);
    });

    let totalDistance = 0;
    let totalCost = 0;
    fillsByVehicle.forEach((vehicleFills) => {
      const ordered = [...vehicleFills].sort((a, b) =>
        a.date_plein.localeCompare(b.date_plein) ||
        a.kilometrage - b.kilometrage ||
        a.id - b.id
      );
      if (ordered.length < 2) return;

      const distance = ordered[ordered.length - 1].kilometrage - ordered[0].kilometrage;
      if (distance <= 0) return;

      totalDistance += distance;
      totalCost += ordered.reduce((sum, fill) => sum + (fill.montant_total || 0), 0) - ordered[0].montant_total;
    });

    return totalDistance > 0 ? totalCost / totalDistance : null;
  }, [fills]);

  const chartData = useMemo(() => {
    const monthNames = ["Janv.","Févr.","Mars","Avr.","Mai","Juin","Juil.","Août","Sept.","Oct.","Nov.","Déc."];
    return Array.from({ length: 6 }, (_, index) => {
      const date = new Date(now.getFullYear(), now.getMonth() - (5 - index), 1);
      const key = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}`;
      return {
        key,
        label: monthNames[date.getMonth()],
        total: fills.filter((fill) => fill.date_plein?.startsWith(key)).reduce((sum, fill) => sum + (fill.montant_total || 0), 0),
      };
    });
  }, [fills]);

  return (
    <main className="page-content" aria-busy={loading}>
      <section className="kpi-grid" aria-label="Indicateurs du mois">
        <KpiCard label="Coût du mois" value={formatMontant(monthlyCost)} icon={CircleDollarSign} />
        <KpiCard label="Litres du mois" value={formatNombre(monthlyLitres, 1)} unit="L" icon={Droplets} />
        <KpiCard label="Consommation moyenne" value={averageConsumption == null ? "—" : `${formatNombre(averageConsumption, 1)} L/100 km`} icon={Gauge} />
        <KpiCard label="Coût au kilomètre" value={costPerKm == null ? "—" : `${formatNombre(costPerKm, 3)} MAD/km`} icon={Route} />
      </section>
      <section className="dashboard-grid">
        <FuelCostChart data={chartData} />
        <AlertsPanel alerts={alerts} />
      </section>
      <FillUpsTable fills={fills} />
    </main>
  );
}
