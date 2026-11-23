// Client HTTP : login (service Chauffeur) + service Entretien (Laravel).
import { clearToken, getToken } from "./auth.js";

const BASE = import.meta.env.VITE_ENTRETIEN_URL ?? "/api";

export class ApiError extends Error {
  constructor(message, status, { code, fields, data } = {}) {
    super(message);
    this.status = status;
    this.code = code;     // ex. KILOMETRAGE_INVALIDE
    this.fields = fields; // erreurs 422 par champ
    this.data = data;
  }
}

function toError(status, body) {
  const err = body?.error;
  if (err) {
    const fields = {};
    if (err.details && typeof err.details === "object") {
      Object.entries(err.details).forEach(([k, v]) => {
        fields[k] = Array.isArray(v) ? v[0] : String(v);
      });
    }
    return new ApiError(err.message || "Une erreur est survenue.", status, {
      code: err.code,
      fields: Object.keys(fields).length ? fields : undefined,
      data: err,
    });
  }
  // Format FastAPI (login)
  const d = body?.detail;
  if (typeof d === "string") return new ApiError(d, status);
  if (d && typeof d === "object" && !Array.isArray(d)) return new ApiError(d.message || "Erreur.", status, { code: d.erreur });
  return new ApiError(`Le service ne repond pas correctement (code ${status}).`, status);
}

async function call(path, { method = "GET", body, params, secured = true, base = BASE } = {}) {
  const qs = params
    ? "?" + new URLSearchParams(Object.entries(params).filter(([, v]) => v !== "" && v != null))
    : "";
  const headers = { Accept: "application/json" };
  if (body) headers["Content-Type"] = "application/json";
  const token = getToken();
  if (secured && token) headers.Authorization = `Bearer ${token}`;

  let res;
  try {
    res = await fetch(`${base}${path}${qs}`, { method, headers, body: body ? JSON.stringify(body) : undefined });
  } catch {
    throw new ApiError("Service injoignable. Verifiez que le conteneur est demarre.", 0);
  }

  if (res.status === 401 && secured) {
    clearToken();
    window.dispatchEvent(new Event("auth:logout"));
    throw new ApiError("Votre session a expire. Reconnectez-vous.", 401);
  }
  if (res.status === 204) return null;
  const data = await res.json().catch(() => null);
  if (!res.ok) throw toError(res.status, data); // inclut 403 ACCES_INTERDIT
  return data;
}

export const api = {
  login: (username, password) => call("/auth/login", { method: "POST", body: { username, password }, secured: false }),
  loginGestionnaire: (username, password) => call("/gestionnaires/login", { method: "POST", body: { username, password }, secured: false }),
  loginChauffeur: (cin, numero_permis) => call("/espace-chauffeur/login", { method: "POST", body: { cin, numero_permis }, secured: false, base: "" }),

  // Comptes gestionnaires (ADMIN)
  gestionnaires: () => call("/gestionnaires"),
  creerGestionnaire: (data) => call("/gestionnaires", { method: "POST", body: data }),
  basculerGestionnaire: (id, actif) => call(`/gestionnaires/${id}/actif`, { method: "PATCH", body: { actif } }),
  motDePasseGestionnaire: (id, password) => call(`/gestionnaires/${id}/mot-de-passe`, { method: "PATCH", body: { password } }),

  // Vehicules
  vehicules: () => call("/vehicules"),
  chauffeurs: () => call("/chauffeurs"),

  // Entretiens
  list: (params = {}) => call("/entretiens", { params }),
  get: (id) => call(`/entretiens/${id}`),
  create: (data) => call("/entretiens", { method: "POST", body: data }),
  update: (id, data) => call(`/entretiens/${id}`, { method: "PUT", body: data }),
  archiver: (id) => call(`/entretiens/${id}`, { method: "DELETE" }),
  demarrer: (id) => call(`/entretiens/${id}/demarrer`, { method: "PATCH" }),
  terminer: (id, data) => call(`/entretiens/${id}/terminer`, { method: "PATCH", body: data }),
  annuler: (id) => call(`/entretiens/${id}/annuler`, { method: "PATCH" }),
  historiqueVehicule: (vid) => call(`/vehicules/${vid}/entretiens`),

  // Alertes
  alertes: (params = {}) => call("/alertes", { params }),

  // Declarations de panne
  declarations: (params = {}) => call("/declarations-panne", { params }),
  declarer: (data) => call("/declarations-panne", { method: "POST", body: data }),
  prendreEnCharge: (id, data) => call(`/declarations-panne/${id}/prendre-en-charge`, { method: "PATCH", body: data }),
  rejeter: (id, data) => call(`/declarations-panne/${id}/rejeter`, { method: "PATCH", body: data }),
};