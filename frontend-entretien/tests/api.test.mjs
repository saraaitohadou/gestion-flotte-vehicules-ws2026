import test from "node:test";
import assert from "node:assert/strict";

const store = new Map();
globalThis.localStorage = {
  getItem: (k) => (store.has(k) ? store.get(k) : null),
  setItem: (k, v) => store.set(k, String(v)),
  removeItem: (k) => store.delete(k),
  clear: () => store.clear(),
};
store.set("flotte.token", "token-de-test");

const { api, ApiError, messageErreur } = await import("../src/api.js");

function reponse(status, corps) {
  globalThis.fetch = async () => ({
    status,
    ok: status >= 200 && status < 300,
    json: async () => corps,
  });
}

test("GET /api/entretien/vehicules envoie le Bearer et ramene la liste", async () => {
  let vu;
  globalThis.fetch = async (url, options) => {
    vu = { url, options };
    return { status: 200, ok: true, json: async () => ({ data: [{ id: 2, statut: "DISPONIBLE" }] }) };
  };
  const resultat = await api.vehicules();
  assert.equal(vu.url, "/api/entretien/vehicules");
  assert.equal(vu.options.method, "GET");
  assert.equal(vu.options.headers.Authorization, "Bearer token-de-test");
  assert.deepEqual(resultat.data, [{ id: 2, statut: "DISPONIBLE" }]);
});

test("une 503 du service Vehicule produit une erreur explicite (pas un faux succes)", async () => {
  reponse(503, { error: { code: "VEHICULE_SERVICE_INDISPONIBLE", message: "Service Vehicule indisponible." } });
  await assert.rejects(
    () => api.vehicules(),
    (e) => {
      assert.ok(e instanceof ApiError);
      assert.equal(e.status, 503);
      assert.equal(e.code, "VEHICULE_SERVICE_INDISPONIBLE");
      assert.match(
        messageErreur(e, "charger les vehicules"),
        /Impossible de charger les vehicules : service indisponible \(VEHICULE_SERVICE_INDISPONIBLE\)\. Service Vehicule indisponible\./
      );
      return true;
    }
  );
});

test("une panne de transport est codee SERVICE_INJOIGNABLE", async () => {
  globalThis.fetch = async () => { throw new Error("ECONNREFUSED"); };
  await assert.rejects(
    () => api.vehicules(),
    (e) => {
      assert.equal(e.status, 0);
      assert.equal(e.code, "SERVICE_INJOIGNABLE");
      assert.match(messageErreur(e, "charger les vehicules"), /service injoignable \(SERVICE_INJOIGNABLE\)/);
      return true;
    }
  );
});

test("le detail vehicule est relaye par GET /api/entretien/vehicules/{id}", async () => {
  let vu;
  globalThis.fetch = async (url) => {
    vu = url;
    return { status: 200, ok: true, json: async () => ({ data: { id: 2, statut: "EN_MAINTENANCE", kilometrage: 62500 } }) };
  };
  const r = await api.vehicule(2);
  assert.equal(vu, "/api/entretien/vehicules/2");
  assert.equal(r.data.statut, "EN_MAINTENANCE");
});

test("la declaration de panne n'envoie pas de chauffeur_id pour un chauffeur", async () => {
  let corps;
  globalThis.fetch = async (_url, options) => {
    corps = JSON.parse(options.body);
    return { status: 201, ok: true, json: async () => ({ data: { id: 7 } }) };
  };
  await api.declarer({ vehicule_id: 2, categorie: "PANNE", description: "Panne", kilometrage: 62500 });
  assert.equal("chauffeur_id" in corps, false);
  assert.equal(corps.vehicule_id, 2);
});
