// Client HTTP : login (service Chauffeur) + service Entretien (Laravel).
import { clearToken, getToken } from "./auth.js";

// L'optionnelle est necessaire pour pouvoir importer ce module dans un test Node.
const BASE = import.meta.env?.VITE_ENTRETIEN_URL ?? "/api";

// Filtre d'archivage de GET /entretiens (valeurs attendues par le backend).
export const ARCHIVES = Object.freeze({
  MASQUER: "exclure",
  INCLURE: "inclure",
  SEULEMENT: "seulement",
});

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
    throw new ApiError("Service injoignable. Verifiez que le conteneur est demarre.", 0, {
      code: "SERVICE_INJOIGNABLE",
    });
  }

  if (res.status === 401 && secured) {
    clearToken();
    if (typeof window !== "undefined") window.dispatchEvent(new Event("auth:logout"));
    throw new ApiError("Votre session a expire. Reconnectez-vous.", 401, { code: "SESSION_EXPIREE" });
  }
  if (res.status === 204) return null;
  const data = await res.json().catch(() => null);
  if (!res.ok) throw toError(res.status, data); // inclut 403 ACCES_INTERDIT
  if (data === null) {
    throw new ApiError("Le service a renvoye une reponse illisible.", res.status, { code: "REPONSE_INVALIDE" });
  }
  return data;
}

/** Message affiche dans l'UI : toujours explicite, jamais un catch silencieux. */
export function messageErreur(err, action = "effectuer l'operation") {
  if (!err) return "Erreur inconnue.";
  const code = err.code ? ` (${err.code})` : "";
  if (err.status === 0) {
    return `Impossible de ${action} : service injoignable${code}. Verifiez que le conteneur est demarre, puis reessayez.`;
  }
  if (err.status === 503) {
    return `Impossible de ${action} : service indisponible${code}. ${err.message} Reessayez dans quelques instants.`;
  }
  if (err.status === 404) return `Impossible de ${action} : ressource introuvable${code}.`;
  if (err.status === 403) return `Acces refuse${code} : ${err.message}`;
  return `${err.message}${code}`.trim();
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
  vehicule: (id) => call(`/vehicules/${id}`),
  chauffeurs: () => call("/chauffeurs"),

  // Entretiens (l'ancien parametre inclure_archives n'est plus envoye : archives fait foi)
  list: (params = {}) => call("/entretiens", {
    params: Object.fromEntries(
      Object.entries(params).filter(([k, v]) => k !== "inclure_archives" && v !== "" && v != null)
    ),
  }),
  get: (id) => call(`/entretiens/${id}`),
  create: (data) => call("/entretiens", { method: "POST", body: data }),
  update: (id, data) => call(`/entretiens/${id}`, { method: "PUT", body: data }),
  archiver: (id) => call(`/entretiens/${id}`, { method: "DELETE" }),
  demarrer: (id) => call(`/entretiens/${id}/demarrer`, { method: "PATCH" }),
  terminer: (id, data) => call(`/entretiens/${id}/terminer`, { method: "PATCH", body: data }),
  annuler: (id) => call(`/entretiens/${id}/annuler`, { method: "PATCH" }),
  historiqueVehicule: (vid) => call(`/vehicules/${vid}/entretiens`),
  carburantVehicule: (vid) => call(`/vehicules/${vid}/carburant`),

  // Alertes
  alertes: (params = {}) => call("/alertes", { params }),

  // Declarations de panne
  declarations: (params = {}) => call("/declarations-panne", { params }),
  declarer: (data) => call("/declarations-panne", { method: "POST", body: data }),
  prendreEnCharge: (id, data) => call(`/declarations-panne/${id}/prendre-en-charge`, { method: "PATCH", body: data }),
  rejeter: (id, data) => call(`/declarations-panne/${id}/rejeter`, { method: "PATCH", body: data }),
};