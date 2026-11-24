const { defineConfig } = require("cypress");

module.exports = defineConfig({
  e2e: {
    specPattern: "e2e/**/*.cy.js",
    supportFile: false,
    video: false,
  },
});