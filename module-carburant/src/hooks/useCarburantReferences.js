import { useEffect, useMemo, useState } from "react";
import { getDrivers, getVehicles } from "../services/api";

export default function useCarburantReferences() {
  const [vehicles, setVehicles] = useState([]);
  const [drivers, setDrivers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    let active = true;
    Promise.allSettled([getVehicles(), getDrivers()])
      .then(([vehicleResult, driverResult]) => {
        if (!active) return;
        if (vehicleResult.status === "fulfilled") setVehicles(vehicleResult.value);
        if (driverResult.status === "fulfilled") setDrivers(driverResult.value);
        const errors = [vehicleResult, driverResult]
          .filter((result) => result.status === "rejected")
          .map((result) => result.reason instanceof Error ? result.reason.message : String(result.reason));
        if (errors.length) setError(errors.join(" ; "));
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => { active = false; };
  }, []);

  const vehicleLabels = useMemo(
    () => new Map(vehicles.map((vehicle) => [vehicle.id, vehicle.immatriculation])),
    [vehicles],
  );
  const driverLabels = useMemo(
    () => new Map(drivers.map((driver) => [driver.id_chauffeur, `${driver.nom} ${driver.prenom}`])),
    [drivers],
  );

  return { vehicles, drivers, vehicleLabels, driverLabels, loading, error };
}
