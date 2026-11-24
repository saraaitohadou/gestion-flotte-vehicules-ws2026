import test from "node:test";
import assert from "node:assert/strict";

import { peutCreerEntretien, STATUTS_VEHICULE_INTERDITS_ENTRETIEN } from "../src/utils.js";

test("les statuts interdits mirrent ceux du backend Entretien", () => {
  assert.deepEqual(STATUTS_VEHICULE_INTERDITS_ENTRETIEN, ["HORS_SERVICE", "ARCHIVE", "EN_MISSION"]);
});

test("un vehicule disponible ou en maintenance accepte un entretien", () => {
  assert.equal(peutCreerEntretien({ id: 1, statut: "DISPONIBLE" }), true);
  assert.equal(peutCreerEntretien({ id: 2, statut: "EN_MAINTENANCE" }), true);
});

test("les vehicules hors service, archives ou en mission sont refuses", () => {
  for (const statut of STATUTS_VEHICULE_INTERDITS_ENTRETIEN) {
    assert.equal(peutCreerEntretien({ id: 1, statut }), false, statut);
  }
});

test("la comparaison des statuts ignore la casse et gere un vehicule absent", () => {
  assert.equal(peutCreerEntretien({ statut: "en_mission" }), false);
  assert.equal(peutCreerEntretien(null), false);
});
