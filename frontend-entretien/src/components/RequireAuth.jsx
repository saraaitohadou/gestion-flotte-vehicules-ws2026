import { useEffect } from "react";
import { Outlet } from "react-router-dom";
import { useAuth } from "./AuthContext.jsx";

const UNIFIED_LOGIN_URL = import.meta.env.VITE_UNIFIED_LOGIN_URL ?? "http://localhost:5174/connexion";

export default function RequireAuth() {
  const { user } = useAuth();

  useEffect(() => {
    if (!user) {
      window.location.replace(`${UNIFIED_LOGIN_URL}?redirect=${encodeURIComponent(window.location.href)}`);
    }
  }, [user]);

  if (!user) return null;
  return <Outlet />;
}