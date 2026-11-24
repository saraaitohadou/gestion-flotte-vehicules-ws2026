import test from "node:test";
import assert from "node:assert/strict";

const b64url = (value) => Buffer.from(JSON.stringify(value)).toString("base64url");

function jwt(role, exp = Math.floor(Date.now() / 1000) + 3600) {
  return `${b64url({ alg: "HS256", typ: "JWT" })}.${b64url({ sub: "x", name: role, role, exp })}.signature`;
}

async function chargerAuth({ search = "", existing = null } = {}) {
  const store = new Map();
  if (existing) store.set("flotte.token", existing);

  const replacements = [];
  globalThis.localStorage = {
    getItem: (k) => store.get(k) ?? null,
    setItem: (k, v) => store.set(k, String(v)),
    removeItem: (k) => store.delete(k),
  };
  globalThis.window = {
    location: {
      search,
      pathname: "/entretien",
      hash: "",
      href: `http://localhost:5176/entretien${search}`,
    },
    history: {
      replaceState: (_state, _title, url) => replacements.push(url),
    },
  };
  globalThis.document = { title: "test" };

  const mod = await import(`../src/auth.js?cas=${encodeURIComponent(search || "vide")}-${existing ? "avec" : "sans"}`);
  return { mod, store, replacements };
}

test("un ?token= admin ne remplace pas une session gestionnaire valide", async () => {
  const gestionnaire = jwt("GESTIONNAIRE");
  const { mod, store, replacements } = await chargerAuth({
    search: "?token=" + encodeURIComponent(jwt("ADMINISTRATEUR")),
    existing: gestionnaire,
  });

  assert.equal(store.get("flotte.token"), gestionnaire);
  assert.equal(mod.userFromToken(store.get("flotte.token")).role, "GESTIONNAIRE");
  assert.equal(replacements.length, 1);
  assert.equal(replacements[0], "/entretien");
});

test("un ?token= est accepte quand il n'existe pas de session", async () => {
  const admin = jwt("ADMINISTRATEUR");
  const { mod, store } = await chargerAuth({ search: "?token=" + encodeURIComponent(admin) });

  assert.equal(store.get("flotte.token"), admin);
  assert.equal(mod.userFromToken(mod.getToken()).role, "ADMINISTRATEUR");
});

test("un ?token= du meme role peut renouveler une session", async () => {
  const ancien = jwt("GESTIONNAIRE", Math.floor(Date.now() / 1000) + 60);
  const neuf = jwt("GESTIONNAIRE", Math.floor(Date.now() / 1000) + 7200);
  const { store } = await chargerAuth({ search: "?token=" + encodeURIComponent(neuf), existing: ancien });

  assert.equal(store.get("flotte.token"), neuf);
});

test("?logout=1 purge le token local", async () => {
  const { store } = await chargerAuth({ search: "?logout=1", existing: jwt("GESTIONNAIRE") });

  assert.equal(store.has("flotte.token"), false);
});
