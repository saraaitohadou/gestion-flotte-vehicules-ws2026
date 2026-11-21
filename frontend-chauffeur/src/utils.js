export const CATEGORIES = ["A", "A1", "B", "C", "D", "EB", "EC", "ED"];

export const STATUTS = {
  DISPONIBLE: { label: "Disponible", tone: "ok" },
  EN_MISSION: { label: "En mission", tone: "info" },
  EN_CONGE: { label: "En congé", tone: "warn" },
  INACTIF: { label: "Inactif", tone: "mute" },
};

// Transitions autorisées côté API (regles.py), hors INACTIF (géré par la suppression)
export const TRANSITIONS = {
  DISPONIBLE: ["EN_MISSION", "EN_CONGE"],
  EN_MISSION: ["DISPONIBLE"],
  EN_CONGE: ["DISPONIBLE"],
};

export const MOTIFS = {
  CHAUFFEUR_INACTIF: "Le chauffeur est désactivé.",
  CHAUFFEUR_EN_CONGE: "Le chauffeur est en congé.",
  DEJA_EN_MISSION: "Le chauffeur est déjà en mission.",
  PERMIS_EXPIRE: "Le permis est expiré.",
};

export const SEUIL_PERMIS = 30;

const jourLocal = (iso) => new Date(`${iso}T00:00:00`);

export function joursRestants(iso) {
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  return Math.round((jourLocal(iso) - today) / 86400000);
}

// Même règle que l'API : valide seulement si la date est strictement après aujourd'hui
export function permisEtat(iso) {
  const j = joursRestants(iso);
  if (j <= 0) return { key: "expire", label: j === 0 ? "Expire aujourd'hui" : `Expiré depuis ${-j} j`, tone: "bad", jours: j };
  if (j <= SEUIL_PERMIS) return { key: "bientot", label: `Expire dans ${j} j`, tone: "warn", jours: j };
  return { key: "valide", label: "Valide", tone: "ok", jours: j };
}

export const formatDate = (iso) =>
  jourLocal(iso).toLocaleDateString("fr-FR", { day: "2-digit", month: "long", year: "numeric" });

export const initiales = (c) => `${c.prenom?.[0] ?? ""}${c.nom?.[0] ?? ""}`.toUpperCase();

export const normaliser = (s) =>
  String(s ?? "").toLowerCase().normalize("NFD").replace(/\p{Diacritic}/gu, "");
