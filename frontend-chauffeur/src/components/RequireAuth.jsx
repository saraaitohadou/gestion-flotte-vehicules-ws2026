import { Outlet } from "react-router-dom";
import { useAuth } from "./AuthContext.jsx";

const UNIFIED_LOGIN_URL =
  import.meta.env.VITE_UNIFIED_LOGIN_URL ?? "http://localhost:5174/connexion";

export default function RequireAuth() {
  const { user } = useAuth();

  if (!user) {
    window.location.replace(
      `${UNIFIED_LOGIN_URL}?redirect=${encodeURIComponent(window.location.href)}`
    );
    return null;
  }

  return <Outlet />;
}