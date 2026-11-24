import { defineConfig } from "vite";

export default defineConfig(({ command }) => {
  const carburantServiceUrl = process.env.VITE_CARBURANT_SERVICE_URL || "http://127.0.0.1:8003";
  return {
    base: command === "serve" ? "/" : "/ui/",
    esbuild: {
      jsxInject: `import React from "react"`,
    },
    server: {
      proxy: {
        "/carburants": { target: carburantServiceUrl, changeOrigin: true },
        "/docs": { target: carburantServiceUrl, changeOrigin: true },
      },
    },
    build: {
      outDir: "frontend-dist",
    },
  };
});
