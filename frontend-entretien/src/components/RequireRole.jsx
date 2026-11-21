import { Navigate, Outlet } from "react-router-dom";
import { useAuth } from "./AuthContext.jsx";

// <RequireRole roles={[...]} /> : si le role n'est pas autorise, retour a l'accueil de son role
export default function RequireRole({ roles }) {
  const { user } = useAuth();
  if (!roles.includes(user?.role)) return <Navigate to="/" replace />;
  return <Outlet />;
}