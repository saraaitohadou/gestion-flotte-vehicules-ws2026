import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

const CHAUFFEUR_TARGET = process.env.VITE_PROXY_TARGET || "http://127.0.0.1:8002";
const VEHICULE_TARGET = process.env.VITE_VEHICULE_PROXY_TARGET || "http://127.0.0.1:8001";

export default defineConfig({
  plugins: [react()],
  server: {
    port: 5174,
    proxy: {
      "/api/gestionnaires": { target: "http://127.0.0.1:8004", changeOrigin: true },
      "/api/vehicules": { target: VEHICULE_TARGET, changeOrigin: true },
      "/espace-chauffeur": { target: CHAUFFEUR_TARGET, changeOrigin: true },
      "/vehicules-disponibles": { target: CHAUFFEUR_TARGET, changeOrigin: true },
      "/api": { target: CHAUFFEUR_TARGET, changeOrigin: true },
    },
  },
});