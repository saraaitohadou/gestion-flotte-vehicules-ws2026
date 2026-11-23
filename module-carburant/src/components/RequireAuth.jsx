import { useEffect } from "react";
import { Outlet } from "react-router-dom";
import { useAuth } from "./AuthContext";

export default function RequireAuth() {
  const { user } = useAuth();
  useEffect(() => {
    if (!user) {
      const authUrl = import.meta.env.VITE_AUTH_URL;
      if (authUrl) window.location.assign(authUrl);
    }
  }, [user]);

  if (!user) {
    return null;
  }
  return <Outlet />;
}
