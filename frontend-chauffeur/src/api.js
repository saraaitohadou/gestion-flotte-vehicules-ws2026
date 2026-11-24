// Client HTTP du service Chauffeur (FastAPI).
// En dev, "/api" est relayé par le proxy Vite. En production :
//   VITE_API_URL=https://.../api/chauffeurs   VITE_AUTH_URL=https://.../api/auth
import { clearToken, getToken } from "./auth.js";

const BASE = import.meta.env.VITE_API_URL || "/api/chauffeurs";
const AUTH_BASE = import.meta.env.VITE_AUTH_URL || "/api/auth";

export class ApiError extends Error {
  constructor(message, status, { code, fields, data } = {}) {
    super(message);
    this.status = status;
    this.code = code;     // ex. PERMIS_EXPIRE, CIN_DEJA_UTILISE
    this.fields = fields; // erreurs de validation par champ (422)
    this.data = data;     // détail brut (ex. id_chauffeur d'un doublon inactif)
  }
}

function toError(status, body) {
  const detail = body?.detail;
  if (Array.isArray(detail)) {
    const fields = {};
    detail.forEach((d) => {
      const champ = d.loc?.[d.loc.length - 1];
      if (champ) fields[champ] = (d.msg || "").replace(/^Value error, /, "");
    });
    return new ApiError("Certains champs sont invalides.", status, { fields });
  }
  if (detail && typeof detail === "object") {
    return new ApiError(detail.message || "Une erreur est survenue.", status, { code: detail.erreur, data: detail });
  }
  if (typeof detail === "string") return new ApiError(detail, status);
  // Gestion du format d'erreur Laravel (service Entretien / Gestionnaires)
  if (body?.error?.message) {
    return new ApiError(body.error.message, status, { code: body.error.code, data: body.error });
  }
  return new ApiError(`Le service ne répond pas correctement (code ${status}).`, status);
}

async function call(url, { method = "GET", body, params, secured = true } = {}) {
  const qs = params
    ? "?" + new URLSearchParams(Object.entries(params).filter(([, v]) => v !== "" && v != null))
    : "";
  const headers = { Accept: "application/json" };
  if (body) headers["Content-Type"] = "application/json";
  const token = getToken();
  if (secured && token) headers.Authorization = `Bearer ${token}`;

  let res;
  try {
    res = await fetch(`${url}${qs}`, { method, headers, body: body ? JSON.stringify(body) : undefined });
  } catch {
    throw new ApiError("Service injoignable. Vérifiez que le conteneur est démarré.", 0);
  }

  // Token absent, expiré ou refusé : on déconnecte l'utilisateur
  if (res.status === 401 && secured) {
    clearToken();
    window.dispatchEvent(new Event("auth:logout"));
    throw new ApiError("Votre session a expiré. Reconnectez-vous.", 401);
  }
  if (res.status === 204) return null;
  const data = await res.json().catch(() => null);
  if (!res.ok) throw toError(res.status, data);
  return data;
}

const request = (path = "", opts) => call(`${BASE}${path}`, opts);

export const api = {
  login: (username, password) =>
    call(`${AUTH_BASE}/login`, { method: "POST", body: { username, password }, secured: false }),

  loginGestionnaire: (username, password) =>
    call("/api/gestionnaires/login", { method: "POST", body: { username, password }, secured: false }),

  loginChauffeur: (cin, numero_permis) =>
    call("/espace-chauffeur/login", { method: "POST", body: { cin, numero_permis }, secured: false }),

  list: (params = {}) => request("", { params: { limit: 200, ...params } }),
  get: (id) => request(`/${id}`),
  create: (data) => request("", { method: "POST", body: data }),
  update: (id, data) => request(`/${id}`, { method: "PUT", body: data }),
  remove: (id) => request(`/${id}`, { method: "DELETE" }), // suppression logique -> INACTIF
  setStatut: (id, statut) => request(`/${id}/statut`, { method: "PATCH", body: { statut } }),
  reactiver: (id, date_expiration_permis) =>
    request(`/${id}/reactiver`, { method: "POST", body: date_expiration_permis ? { date_expiration_permis } : undefined }),
  permisExpires: (jours = 30) => request("/permis-expires", { params: { jours } }),
  eligibilite: (id) => request(`/${id}/eligibilite`),
  compatibilite: (id, categorie_vehicule) => request(`/${id}/compatibilite`, { params: { categorie_vehicule } }),
  vehicules: async () => {
    const res = await call("/api/vehicules").catch(() => []);
    const liste = Array.isArray(res) ? res : (res?.data || res?.vehicules || []);
    return liste.map((v) => ({
      id: v.id,
      immatriculation: v.immatriculation,
      marque: v.marque,
      modele: v.modele,
      statut: v.statut || v.etat || "DISPONIBLE",
    }));
  },
};