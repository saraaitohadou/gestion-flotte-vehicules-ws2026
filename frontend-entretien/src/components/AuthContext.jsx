import { createContext, useCallback, useContext, useEffect, useState } from "react";
import { api } from "../api.js";
import { clearToken, getToken, setToken, userFromToken } from "../auth.js";

const AuthCtx = createContext(null);
export const useAuth = () => useContext(AuthCtx);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(() => userFromToken(getToken()));

  useEffect(() => {
    const onLogout = () => setUser(null);
    window.addEventListener("auth:logout", onLogout);
    return () => window.removeEventListener("auth:logout", onLogout);
  }, []);

  useEffect(() => {
    if (!user?.exp) return;
    const delai = Math.max(user.exp * 1000 - Date.now(), 0);
    const t = setTimeout(() => { clearToken(); setUser(null); }, delai);
    return () => clearTimeout(t);
  }, [user]);

  const loginWithToken = useCallback((token) => {
    setToken(token);
    setUser(userFromToken(token));
  }, []);

  // Administrateur (service Chauffeur)
  const login = useCallback(async (username, password) => {
    const data = await api.login(username, password);
    loginWithToken(data.access_token);
  }, [loginWithToken]);

  // Gestionnaire (service Entretien)
  const loginGestionnaire = useCallback(async (username, password) => {
    const data = await api.loginGestionnaire(username, password);
    loginWithToken(data.access_token);
  }, [loginWithToken]);

  // Chauffeur (service Chauffeur : CIN + permis)
  const loginChauffeur = useCallback(async (cin, permis) => {
    const data = await api.loginChauffeur(cin, permis);
    loginWithToken(data.access_token);
  }, [loginWithToken]);

  const logout = useCallback(() => {
    clearToken();
    setUser(null);
  }, []);

  return (
    <AuthCtx.Provider value={{ user, login, loginGestionnaire, loginChauffeur, loginWithToken, logout }}>
      {children}
    </AuthCtx.Provider>
  );
}