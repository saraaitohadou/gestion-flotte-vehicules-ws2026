import { createContext, useCallback, useContext, useEffect, useState } from "react";
import { clearToken, getToken, setToken, userFromToken } from "../auth";

const AuthContext = createContext(null);
export const useAuth = () => useContext(AuthContext);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(() => userFromToken(getToken()));

  useEffect(() => {
    if (!user?.exp) return undefined;
    const delay = Math.max(user.exp * 1000 - Date.now(), 0);
    const timer = setTimeout(() => {
      clearToken();
      setUser(null);
    }, delay);
    return () => clearTimeout(timer);
  }, [user]);

  useEffect(() => {
    const onLogout = () => setUser(null);
    window.addEventListener("auth:logout", onLogout);
    return () => window.removeEventListener("auth:logout", onLogout);
  }, []);

  const loginWithToken = useCallback((token) => {
    setToken(token);
    setUser(userFromToken(token));
  }, []);

  const logout = useCallback(() => {
    clearToken();
    setUser(null);
  }, []);

  return (
    <AuthContext.Provider value={{ user, loginWithToken, logout }}>
      {children}
    </AuthContext.Provider>
  );
}
