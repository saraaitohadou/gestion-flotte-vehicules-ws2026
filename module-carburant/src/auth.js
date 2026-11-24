const KEY = "flotte.carburant.token";
const PORTAIL_KEY = "flotte.token";

export const getToken = () => {
  const queryToken = new URLSearchParams(window.location.search).get("token") || ""; const fragment = queryToken || (window.location.hash.startsWith("#token=") ? window.location.hash.slice("#token=".length) : "");

  if (fragment) {
    const fragmentUser = userFromToken(fragment);
    if (!fragmentUser) {
      clearToken();
      window.history.replaceState({}, document.title, window.location.pathname);
      return null;
    }
    setToken(fragment);
  }

  if (fragment || window.location.hash.startsWith("#token=")) {
    window.history.replaceState({}, document.title, window.location.pathname);
  }

  const storedToken = localStorage.getItem(KEY) || localStorage.getItem(PORTAIL_KEY);
  if (!storedToken) return null;
  if (!userFromToken(storedToken)) {
    clearToken();
    return null;
  }
  return storedToken;
};

export const setToken = (token) => {
  localStorage.setItem(KEY, token);
  localStorage.setItem(PORTAIL_KEY, token);
};

export const clearToken = () => {
  localStorage.removeItem(KEY);
  localStorage.removeItem(PORTAIL_KEY);
};

export function userFromToken(token) {
  if (!token) return null;
  try {
    const payload = JSON.parse(atob(token.split(".")[1].replace(/-/g, "+").replace(/_/g, "/")));
    if (!payload.exp || payload.exp * 1000 <= Date.now()) return null;
    return {
      username: payload.name ?? payload.sub,
      role: payload.role,
      id_chauffeur: payload.id_chauffeur ?? (payload.role === "CHAUFFEUR" ? payload.sub : undefined),
      exp: payload.exp,
    };
  } catch {
    return null;
  }
}
