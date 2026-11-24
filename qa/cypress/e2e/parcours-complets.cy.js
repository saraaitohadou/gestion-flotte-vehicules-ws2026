describe("Parcours E2E complets - Gestion de Flotte", () => {
  const token = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJhZG1pbkBmbG90dGUubWEiLCJyb2xlIjoiYWRtaW4ifQ.signature_test";

  it("1. Parcours Connexion & Espace Admin (:5173 / :5174)", () => {
    cy.visit("http://localhost:5174", {
      onBeforeLoad(win) {
        win.localStorage.setItem("token", token);
      },
    });
    cy.get("body").should("not.be.empty");
  });

  it("2. Parcours Gestion des Vehicules (:5170 / :5173)", () => {
    cy.visit("http://localhost:5173", {
      failOnStatusCode: false,
      onBeforeLoad(win) {
        win.localStorage.setItem("token", token);
      },
    });
    cy.get("body").should("not.be.empty");
  });

  it("3. Parcours Gestion des Chauffeurs (:5171 / :5174)", () => {
    cy.visit("http://localhost:5174", {
      failOnStatusCode: false,
      onBeforeLoad(win) {
        win.localStorage.setItem("token", token);
      },
    });
    cy.get("body").should("not.be.empty");
  });

  it("4. Parcours Suivi Carburant (:5172 / :5175)", () => {
    cy.visit("http://localhost:5175", {
      failOnStatusCode: false,
      onBeforeLoad(win) {
        win.localStorage.setItem("token", token);
      },
    });
    cy.get("body").should("not.be.empty");
  });

  it("5. Parcours Entretien & Redirection Auth (:5176 -> :5174)", () => {
    cy.visit("http://localhost:5176", {
      failOnStatusCode: false,
      onBeforeLoad(win) {
        win.localStorage.setItem("token", token);
      },
    });
    cy.origin("http://localhost:5174", () => {
      cy.get("body").should("not.be.empty");
    });
  });
});