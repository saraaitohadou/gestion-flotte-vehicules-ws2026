// Stockage du token et lecture de ses informations (sans vérifier la signature : c'est le rôle du serveur)
const KEY = "flotte.token";

const urlParams = new URLSearchParams(window.location.search);
if (urlParams.get("logout") === "1") {
  localStorage.removeItem(KEY);
  localStorage.removeItem("chauffeur_token");
  localStorage.removeItem("chauffeur_role");
  window.history.replaceState({}, document.title, window.location.pathname);
}
// Meme regle que dans le module Entretien : un jeton recu par URL ne remplace
// jamais une session valide appartenant a un autre role.
const urlToken = urlParams.get("token");
if (urlToken) {
  const courant = userFromToken(localStorage.getItem(KEY));
  const entrant = userFromToken(urlToken);
  if (entrant && (!courant || courant.role === entrant.role)) {
    localStorage.setItem(KEY, urlToken);
  }
  const propres = new URLSearchParams(window.location.search);
  propres.delete("token");
  const query = propres.toString();
  window.history.replaceState(
    {},
    document.title,
    window.location.pathname + (query ? `?${query}` : "") + window.location.hash,
  );
}


export const getToken = () => localStorage.getItem(KEY);
export const setToken = (t) => localStorage.setItem(KEY, t);
export const clearToken = () => localStorage.removeItem(KEY);

export function userFromToken(token) {
  if (!token) return null;
  try {
    const payload = JSON.parse(atob(token.split(".")[1].replace(/-/g, "+").replace(/_/g, "/")));
    if (payload.exp && payload.exp * 1000 < Date.now()) return null;
    return { username: payload.sub, role: payload.role, exp: payload.exp };
  } catch {
    return null;
  }
}
