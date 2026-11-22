// Stockage du token et lecture de ses informations (sans vérifier la signature : c'est le rôle du serveur)
const KEY = "flotte.token";

export const getToken = () => localStorage.getItem(KEY);
export const setToken = (t) => localStorage.setItem(KEY, t);
export const clearToken = () => localStorage.removeItem(KEY);

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
