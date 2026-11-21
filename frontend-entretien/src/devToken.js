// ATTENTION : usage developpement uniquement (le secret est visible dans le navigateur).
// Le meme secret doit etre dans module-entretien/.env (JWT_SECRET).
const SECRET = import.meta.env.VITE_DEV_JWT_SECRET || "secret-dev-a-changer";

const b64url = (bytes) =>
  btoa(String.fromCharCode(...new Uint8Array(bytes))).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
const enc = (s) => new TextEncoder().encode(s);

export async function makeDevToken(role, sub) {
  const header = b64url(enc(JSON.stringify({ alg: "HS256", typ: "JWT" })));
  const exp = Math.floor(Date.now() / 1000) + 3600;
  const payload = b64url(enc(JSON.stringify({ sub: String(sub), role, exp })));
  const key = await crypto.subtle.importKey("raw", enc(SECRET), { name: "HMAC", hash: "SHA-256" }, false, ["sign"]);
  const sig = await crypto.subtle.sign("HMAC", key, enc(`${header}.${payload}`));
  return `${header}.${payload}.${b64url(sig)}`;
}