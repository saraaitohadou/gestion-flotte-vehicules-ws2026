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
const urlToken = urlParams.get("token");
if (urlToken) {
  storage?.setItem(KEY, urlToken);
  if (hasWindow) window.history.replaceState({}, document.title, window.location.pathname);
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
