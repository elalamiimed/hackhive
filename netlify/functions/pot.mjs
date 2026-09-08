// netlify/functions/pot.mjs
import { getStore } from "@netlify/blobs";

const ACCESS_CODE = "HITSZ2025";
const SEED = 20250401;
const store = getStore("hackhive-pot");

function matchAtMs() {
  const sz = new Date(Date.now() + 8 * 3600e3);
  const day = sz.getUTCDay();
  let diff = (5 - day + 7) % 7;
  if (diff === 0) diff = 7;
  return Date.UTC(sz.getUTCFullYear(), sz.getUTCMonth(), sz.getUTCDate() + diff, 0, 0, 0) - 8 * 3600e3 - 3600e3;
}

function seededShuffle(arr, seed) {
  const a = arr.slice();
  let t = seed >>> 0;
  const rnd = () => {
    t += 0x6D2B79F5;
    let r = Math.imul(t ^ (t >>> 15), 1 | t);
    r ^= r + Math.imul(r ^ (r >>> 7), 61 | r);
    return ((r ^ (r >>> 14)) >>> 0) / 4294967296;
  };
  for (let i = a.length - 1; i > 0; i--) {
    const j = Math.floor(rnd() * (i + 1));
    const tmp = a[i]; a[i] = a[j]; a[j] = tmp;
  }
  return a;
}

function pairs(pot) {
  if (Date.now() < matchAtMs()) return null;
  const s = seededShuffle(pot.map(p => p.name), SEED);
  const m = {};
  for (let i = 0; i + 1 < s.length; i += 2) { 
    m[s[i]] = s[i + 1]; 
    m[s[i + 1]] = s[i]; 
  }
  if (s.length % 2) m[s[s.length - 1]] = null;
  return m;
}

export default async (req) => {
  if (req.method === "GET") {
    const pot = (await store.get("pot", { type: "json" })) || [];
    return Response.json({ pot: pot.map(p => p.name), matched: pairs(pot), matchAt: matchAtMs() });
  }

  if (req.method === "POST") {
    const body = await req.json().catch(() => ({}));
    const name = String(body.name || "").trim().slice(0, 40);
    const code = String(body.code || "").trim();
    
    if (!name) return Response.json({ error: "write your name first" }, { status: 400 });
    if (code !== ACCESS_CODE) return Response.json({ error: "wrong code" }, { status: 403 });
    
    const pot = (await store.get("pot", { type: "json" })) || [];
    if (pot.some(p => p.name.toLowerCase() === name.toLowerCase())) {
      return Response.json({ error: "you're already in the pot" }, { status: 409 });
    }
    
    const token = crypto.randomUUID();
    pot.push({ name, token, at: Date.now() });
    await store.set("pot", JSON.stringify(pot));
    return Response.json({ ok: true, token });
  }

  if (req.method === "DELETE") {
    if (Date.now() >= matchAtMs()) {
      return Response.json({ error: "too late — teams are locked" }, { status: 403 });
    }
    const body = await req.json().catch(() => ({}));
    const token = String(body.token || "");
    
    const pot = (await store.get("pot", { type: "json" })) || [];
    const next = pot.filter(p => p.token !== token);
    if (next.length === pot.length) return Response.json({ error: "not found" }, { status: 404 });
    
    await store.set("pot", JSON.stringify(next));
    return Response.json({ ok: true });
  }

  return Response.json({ error: "method not allowed" }, { status: 405 });
};