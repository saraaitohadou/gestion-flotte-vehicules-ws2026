import { getToken } from "../auth";

const API = "";
async function request(path, options = {}) {
  const token = getToken();
  const authHeaders = token ? { Authorization: `Bearer ${token}` } : {};
  let response;
  try {
    response = await fetch(API + path, {
      headers: { "Content-Type": "application/json", ...authHeaders, ...(options.headers || {}) },
      ...options,
    });
  } catch {
    throw new Error("Impossible de joindre le service Carburant.");
  }
  if (response.status === 204) return null;
  const data = await response.json().catch(() => null);
  if (!response.ok) {
    const detail = data?.detail;
    if (Array.isArray(detail)) {
      throw new Error(detail.map((item) => `${(item.loc || []).slice(1).join(".")} : ${item.msg}`).join(" ; "));
    }
    throw new Error(typeof detail === "string" ? detail : `Erreur ${response.status}`);
  }
  return data;
}

export const getDashboardData = () => Promise.all([
  request("/carburants?limit=500"),
  request("/carburants/anomalies"),
]).then(([pleins, alertes]) => ({ pleins, alertes }));

export const getFills = (params) => {
  const search = new URLSearchParams();
  Object.entries(params || {}).forEach(([key, value]) => {
    if (value !== "" && value != null) search.set(key, value);
  });
  const query = search.toString();
  return request(`/carburants${query ? `?${query}` : ""}`);
};
export const getAlerts = (params) => {
  const search = new URLSearchParams(params);
  for (const key of [...search.keys()]) if (!search.get(key)) search.delete(key);
  const query = search.toString();
  return request(`/carburants/anomalies${query ? `?${query}` : ""}`);
};
export const getFill = (id) => request(`/carburants/${id}`);
export const getVehicles = () => request("/carburants/references/vehicules");
export const getDrivers = () => request("/carburants/references/chauffeurs");
export const getLatestMaintenance = (vehicleId) =>
  request(`/carburants/references/vehicules/${encodeURIComponent(vehicleId)}/dernier-entretien`);
export const createFill = (body) => request("/carburants", { method: "POST", body: JSON.stringify(body) });
export const updateFill = (id, body) => request(`/carburants/${id}`, { method: "PATCH", body: JSON.stringify(body) });
export const deleteFill = (id) => request(`/carburants/${id}`, { method: "DELETE" });
export const getStats = (vehicleId, params) => {
  const search = new URLSearchParams(params);
  for (const key of [...search.keys()]) if (!search.get(key)) search.delete(key);
  const query = search.toString();
  return request(`/carburants/stats/vehicules/${encodeURIComponent(vehicleId)}${query ? `?${query}` : ""}`);
};

