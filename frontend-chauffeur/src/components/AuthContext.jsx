import { createContext, useCallback, useContext, useEffect, useState } from "react";
import { api } from "../api.js";
import { clearToken, getToken, setToken, userFromToken } from "../auth.js";

const AuthCtx = createContext(null);
export const useAuth = () => useContext(AuthCtx);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(() => userFromToken(getToken()));

  // Déconnexion déclenchée par api.js (réponse 401)
  useEffect(() => {
    const onLogout = () => setUser(null);
    window.addEventListener("auth:logout", onLogout);
    return () => window.removeEventListener("auth:logout", onLogout);
  }, []);

  // Déconnexion automatique à l'expiration du token
  useEffect(() => {
    if (!user?.exp) return;
    const delai = Math.max(user.exp * 1000 - Date.now(), 0);
    const t = setTimeout(() => { clearToken(); setUser(null); }, delai);
    return () => clearTimeout(t);
  }, [user]);

  const login = useCallback(async (username, password) => {
    const data = await api.login(username, password);
    setToken(data.access_token);
    setUser(userFromToken(data.access_token));
  }, []);

  const logout = useCallback(() => {
    clearToken();
    setUser(null);
  }, []);

  return <AuthCtx.Provider value={{ user, login, logout }}>{children}</AuthCtx.Provider>;
}
