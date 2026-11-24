export const STATUTS = {
  PLANIFIE: { label: "Planifie", tone: "info" },
  EN_COURS: { label: "En cours", tone: "warn" },
  TERMINE:  { label: "Termine", tone: "ok" },
  ANNULE:   { label: "Annule", tone: "mute" },
};

export const TYPES = {
  PREVENTIF: { label: "Preventif", tone: "info" },
  CORRECTIF: { label: "Correctif", tone: "bad" },
};

export const CATEGORIES = {
  PREVENTIF: ["VIDANGE", "PNEUS", "FREINS", "VISITE_TECHNIQUE"],
  CORRECTIF: ["PANNE", "REPARATION"],
};

// Miroir de VehiculeClient::STATUTS_INTERDITS_ENTRETIEN (cote API Entretien).
// Un vehicule DISPONIBLE ou EN_MAINTENANCE peut recevoir un entretien.
export const STATUTS_VEHICULE_INTERDITS_ENTRETIEN = ["HORS_SERVICE", "ARCHIVE", "EN_MISSION"];

export const peutCreerEntretien = (vehicule) =>
  Boolean(vehicule) &&
  !STATUTS_VEHICULE_INTERDITS_ENTRETIEN.includes(String(vehicule.statut ?? "").toUpperCase());

export const VEHICULE_BADGES = {
  DISPONIBLE: { label: "Disponible", tone: "ok" },
  EN_MAINTENANCE: { label: "En maintenance", tone: "info" },
  EN_MISSION: { label: "En mission", tone: "warn" },
  HORS_SERVICE: { label: "Hors service", tone: "bad" },
  ARCHIVE: { label: "Archive", tone: "mute" },
};

export const formatDate = (iso) =>
  iso ? new Date(`${iso}T00:00:00`).toLocaleDateString("fr-FR", { day: "2-digit", month: "short", year: "numeric" }) : "-";

export const formatMoney = (n) =>
  new Intl.NumberFormat("fr-FR", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n ?? 0) + " MAD";

export const formatKm = (n) => (n == null ? "-" : new Intl.NumberFormat("fr-FR").format(n) + " km");