// netlify/functions/pot.mjs
// shared pot. deliberately plain syntax: no arrow functions, no bitwise
// operators, no destructuring. nothing a copy-paste tool can mangle.
import { getStore } from "@netlify/blobs";

var ACCESS_CODE = "HITSZ2025";
var SEED = 20250401;
var store = getStore("hackhive-pot");

function matchAtMs() {
  var sz = new Date(Date.now() + 8 * 3600 * 1000);
  var day = sz.getUTCDay();
  var diff = (5 - day + 7) % 7;
  if (diff === 0) { diff = 7; }
  return Date.UTC(sz.getUTCFullYear(), sz.getUTCMonth(), sz.getUTCDate() + diff, 0, 0, 0) - 9 * 3600 * 1000;
}

function seededShuffle(arr, seed) {
  var a = arr.slice();
  var t = seed % 2147483647;
  if (t === 0) { t = 1; }
  function nextRnd() {
    t = (t * 48271) % 2147483647;
    return t / 2147483647;
  }
  for (var i = a.length - 1; i !== 0; i--) {
    var j = Math.floor(nextRnd() * (i + 1));
    var tmp = a[i];
    a[i] = a[j];
    a[j] = tmp;
  }
  return a;
}

function pairs(pot) {
  if (matchAtMs() - Date.now() <= 0) {
    var s = seededShuffle(pot.map(function (p) { return p.name; }), SEED);
    var m = {};
    for (var i = 0; i + 1 < s.length; i += 2) {
      m[s[i]] = s[i + 1];
      m[s[i + 1]] = s[i];
    }
    if (s.length % 2 === 1) { m[s[s.length - 1]] = null; }
    return m;
  }
  return null;
}

export default async function handler(req) {
  if (req.method === "GET") {
    var pot = (await store.get("pot", { type: "json" })) || [];
    return Response.json({ pot: pot.map(function (p) { return p.name; }), matched: pairs(pot), matchAt: matchAtMs() });
  }

  if (req.method === "POST") {
    var body = await req.json().catch(function () { return {}; });
    var name = String(body.name || "").trim().slice(0, 40);
    var code = String(body.code || "").trim();
    if (!name) { return Response.json({ error: "write your name first" }, { status: 400 }); }
    if (code !== ACCESS_CODE) { return Response.json({ error: "wrong code" }, { status: 403 }); }
    if (matchAtMs() - Date.now() <= 0) { return Response.json({ error: "too late, teams are locked" }, { status: 403 }); }
    var pot2 = (await store.get("pot", { type: "json" })) || [];
    var dupe = pot2.some(function (p) { return p.name.toLowerCase() === name.toLowerCase(); });
    if (dupe) { return Response.json({ error: "already in the pot" }, { status: 409 }); }
    var token = crypto.randomUUID();
    pot2.push({ name: name, token: token, at: Date.now() });
    await store.set("pot", JSON.stringify(pot2));
    return Response.json({ ok: true, token: token });
  }

  if (req.method === "DELETE") {
    if (matchAtMs() - Date.now() <= 0) { return Response.json({ error: "too late, teams are locked" }, { status: 403 }); }
    var body3 = await req.json().catch(function () { return {}; });
    var token3 = String(body3.token || "");
    var pot3 = (await store.get("pot", { type: "json" })) || [];
    var next = pot3.filter(function (p) { return p.token !== token3; });
    if (next.length === pot3.length) { return Response.json({ error: "not found" }, { status: 404 }); }
    await store.set("pot", JSON.stringify(next));
    return Response.json({ ok: true });
  }

  return Response.json({ error: "method not allowed" }, { status: 405 });
}