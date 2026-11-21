import { getToken, userFromToken } from "./auth.js";

export const ROLES = { ADMIN: "ADMINISTRATEUR", GEST: "GESTIONNAIRE", CHAUF: "CHAUFFEUR" };
export const LIBELLES = { ADMINISTRATEUR: "Administrateur", GESTIONNAIRE: "Gestionnaire", CHAUFFEUR: "Chauffeur" };

export const STAFF = [ROLES.ADMIN, ROLES.GEST];        // gestion des entretiens
export const DECLARANTS = [ROLES.CHAUF, ROLES.ADMIN];  // declarer une panne

export const currentRole = () => userFromToken(getToken())?.role ?? null;
export const isEntretienStaff = (role) => STAFF.includes(role);

// Page d'accueil de chaque role
export function homeFor(role) {
  if (role === ROLES.ADMIN || role === ROLES.GEST) return "/entretien";
  if (role === ROLES.CHAUF) return "/pannes/nouvelle";
  return null;
}