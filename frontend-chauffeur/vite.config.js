import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

// Le proxy évite les problèmes CORS en développement :
// le navigateur appelle /api/... et Vite relaie vers le service Chauffeur (port 8000).
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: {
      "/api": { target: process.env.VITE_PROXY_TARGET || "http://127.0.0.1:8002", changeOrigin: true },
      "/espace-chauffeur": { target: process.env.VITE_PROXY_TARGET || "http://127.0.0.1:8002", changeOrigin: true },
      "/vehicules-disponibles": { target: process.env.VITE_PROXY_TARGET || "http://127.0.0.1:8002", changeOrigin: true },
    },
  },
});
