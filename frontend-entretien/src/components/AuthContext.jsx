import { createContext, useCallback, useContext, useEffect, useState } from "react";
import { api } from "../api.js";
import { clearToken, getToken, setToken, userFromToken } from "../auth.js";

const UNIFIED_LOGIN_URL = import.meta.env.VITE_UNIFIED_LOGIN_URL ?? "http://localhost:5174/connexion";

const AuthCtx = createContext(null);
export const useAuth = () => useContext(AuthCtx);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(() => userFromToken(getToken()));

  const redirigerVersLoginUnique = useCallback((isLogout = false) => {
    clearToken();
    setUser(null);
    const url = isLogout
      ? `${UNIFIED_LOGIN_URL}?logout=1`
      : `${UNIFIED_LOGIN_URL}?redirect=${encodeURIComponent(window.location.href)}`;
    window.location.replace(url);
  }, []);

  useEffect(() => {
    const onLogout = () => redirigerVersLoginUnique(false);
    window.addEventListener("auth:logout", onLogout);
    return () => window.removeEventListener("auth:logout", onLogout);
  }, [redirigerVersLoginUnique]);

  useEffect(() => {
    if (!user?.exp) return;
    const delai = Math.max(user.exp * 1000 - Date.now(), 0);
    const t = setTimeout(() => redirigerVersLoginUnique(false), delai);
    return () => clearTimeout(t);
  }, [user, redirigerVersLoginUnique]);

  const loginWithToken = useCallback((token) => {
    setToken(token);
    setUser(userFromToken(token));
  }, []);

  const login = useCallback(async (username, password) => {
    const data = await api.login(username, password);
    loginWithToken(data.access_token);
  }, [loginWithToken]);

  const loginGestionnaire = useCallback(async (username, password) => {
    const data = await api.loginGestionnaire(username, password);
    loginWithToken(data.access_token);
  }, [loginWithToken]);

  const loginChauffeur = useCallback(async (cin, permis) => {
    const data = await api.loginChauffeur(cin, permis);
    loginWithToken(data.access_token);
  }, [loginWithToken]);

  const logout = useCallback(() => {
    redirigerVersLoginUnique(true);
  }, [redirigerVersLoginUnique]);

  return (
    <AuthCtx.Provider value={{ user, login, loginGestionnaire, loginChauffeur, loginWithToken, logout }}>
      {children}
    </AuthCtx.Provider>
  );
}