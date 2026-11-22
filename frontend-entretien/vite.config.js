import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

const ENTRETIEN = process.env.VITE_ENTRETIEN_TARGET || "http://127.0.0.1:8004";
const AUTH = process.env.VITE_AUTH_TARGET || "http://127.0.0.1:8002";

// Le proxy evite CORS : le navigateur appelle /api/... et Vite relaie.
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5176,
    proxy: {
      // Login administrateur et chauffeur : service Chauffeur (FastAPI)
      "/api/auth": { target: AUTH, changeOrigin: true },
      "/espace-chauffeur": { target: AUTH, changeOrigin: true },
      // Tout le reste (dont /api/gestionnaires) : service Entretien (Laravel)
      "/api": { target: ENTRETIEN, changeOrigin: true },
    },
  },
});