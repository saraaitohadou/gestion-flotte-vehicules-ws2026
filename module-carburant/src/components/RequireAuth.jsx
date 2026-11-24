import { useEffect } from "react";
import { Outlet } from "react-router-dom";
import { useAuth } from "./AuthContext";

export default function RequireAuth() {
  const { user } = useAuth();
  useEffect(() => {
    if (!user) {
      const baseAuth = import.meta.env.VITE_AUTH_URL || "http://localhost:5174/connexion";
      const sep = baseAuth.includes("?") ? "&" : "?";
      window.location.replace(`${baseAuth}${sep}logout=1`);
    }
  }, [user]);

  if (!user) {
    return null;
  }
  return <Outlet />;
}
