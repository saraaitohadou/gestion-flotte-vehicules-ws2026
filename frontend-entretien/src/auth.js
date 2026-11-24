// Stockage du token et lecture de ses informations (sans vérifier la signature : c'est le rôle du serveur)
const KEY = "flotte.token";

// Ces gardes permettent aussi de charger le client HTTP dans un test Node
// (aucun window/localStorage) sans casser le comportement du navigateur.
const hasWindow = typeof window !== "undefined";
const storage = (() => {
  try { return typeof localStorage === "undefined" ? null : localStorage; }
  catch { return null; }
})();

const urlParams = new URLSearchParams(hasWindow ? window.location.search : "");
if (hasWindow && urlParams.get("logout") === "1") {
  storage?.removeItem(KEY);
  window.history.replaceState({}, document.title, window.location.pathname);
}
// Un token transmis par URL ne doit jamais ecraser une session valide d'un autre role.
// C'etait le chemin qui transformait un gestionnaire en admin : le portail envoyait
// son propre JWT administrateur quand on cliquait sur la carte "Maintenance".
const urlToken = urlParams.get("token");
if (hasWindow && urlToken) {
  const courant = userFromToken(storage?.getItem(KEY));
  const entrant = userFromToken(urlToken);
  if (entrant && (!courant || courant.role === entrant.role)) {
    storage?.setItem(KEY, urlToken);
  }
  // Le parametre est toujours retire de l'URL, meme quand le token est refuse.
  const propres = new URLSearchParams(window.location.search);
  propres.delete("token");
  const query = propres.toString();
  window.history.replaceState(
    {},
    document.title,
    window.location.pathname + (query ? `?${query}` : "") + window.location.hash,
  );
}

export const getToken = () => storage?.getItem(KEY) ?? null;
export const setToken = (t) => storage?.setItem(KEY, t);
export const clearToken = () => storage?.removeItem(KEY);

export function userFromToken(token) {
  if (!token) return null;
  try {
    const payload = JSON.parse(atob(token.split(".")[1].replace(/-/g, "+").replace(/_/g, "/")));
    if (payload.exp && payload.exp * 1000 < Date.now()) return null;
    return { username: payload.name ?? payload.sub, role: payload.role, exp: payload.exp };
  } catch {
    return null;
  }
}
