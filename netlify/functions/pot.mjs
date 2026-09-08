// netlify/functions/pot.mjs
// shared pot. deliberately plain syntax: no arrow functions, no bitwise
// operators, no destructuring. nothing a copy-paste tool can mangle.
//
// members are stored as separate blobs ("m:<uuid>") instead of one growing
// array. a single "pot" key with read-modify-write loses registrations when
// two joins race (both read the old list, the later write wins and silently
// erases the earlier member). per-member keys make a join append-only.
import { getStore } from "@netlify/blobs";

var ACCESS_CODE = "HITSZ2025";
var SEED = 20250401;
var PREFIX = "m:";
var store = getStore({ name: "hackhive-pot" });

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

function pairs(names) {
  if (matchAtMs() - Date.now() <= 0) {
    var s = seededShuffle(names, SEED);
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

async function readPot() {
  var out = [];
  // list() auto-paginates and resolves to { blobs: [{ key, etag }, ...] }
  var page = await store.list({ prefix: PREFIX });
  var blobs = (page && page.blobs) ? page.blobs : [];
  var readErr = null;
  for (var i = 0; i < blobs.length; i++) {
    try {
      var rec = await store.get(blobs[i].key, { type: "json" });
      if (rec && rec.name && rec.token) { out.push(rec); }
    } catch (e) {
      // never silently truncate the pot: a truncated 200 would make every
      // client replace the real roster with an empty pot. abort the read
      // instead so the handler returns 503 and viewers keep the last good
      // snapshot.
      readErr = e;
    }
  }
  if (readErr) { throw readErr; }
  // insertion order, ties broken by name so every viewer sorts identically
  out.sort(function (a, b) {
    if (a.at !== b.at) { return a.at - b.at; }
    return a.name < b.name ? -1 : a.name > b.name ? 1 : 0;
  });
  return out;
}

export default async function handler(req) {
  if (req.method === "GET") {
    var pot, names;
    try {
      pot = await readPot();
      names = pot.map(function (p) { return p.name; });
    } catch (e) {
      // never report a transient storage error as "nobody has registered" —
      // that would make every client flash an empty pot.
      return Response.json({ error: "pot temporarily unavailable" }, { status: 503 });
    }
    return Response.json({ pot: names, matched: pairs(names), matchAt: matchAtMs() });
  }

  if (req.method === "POST") {
    var body = await req.json().catch(function () { return {}; });
    var name = String(body.name || "").trim().slice(0, 40);
    var code = String(body.code || "").trim();
    if (!name) { return Response.json({ error: "write your name first" }, { status: 400 }); }
    if (code !== ACCESS_CODE) { return Response.json({ error: "wrong code" }, { status: 403 }); }
    if (matchAtMs() - Date.now() <= 0) { return Response.json({ error: "too late, teams are locked" }, { status: 403 }); }
    var pot2;
    try { pot2 = await readPot(); }
    catch (e) { return Response.json({ error: "pot temporarily unavailable" }, { status: 503 }); }
    var dupe = pot2.some(function (p) { return p.name.toLowerCase() === name.toLowerCase(); });
    if (dupe) { return Response.json({ error: "already in the pot" }, { status: 409 }); }
    var token = crypto.randomUUID();
    await store.set(PREFIX + token, JSON.stringify({ name: name, token: token, at: Date.now() }));
    return Response.json({ ok: true, token: token });
  }

  if (req.method === "DELETE") {
    if (matchAtMs() - Date.now() <= 0) { return Response.json({ error: "too late, teams are locked" }, { status: 403 }); }
    var body3 = await req.json().catch(function () { return {}; });
    var token3 = String(body3.token || "");
    var pot3;
    try { pot3 = await readPot(); }
    catch (e) { return Response.json({ error: "pot temporarily unavailable" }, { status: 503 }); }
    var gone = false;
    for (var i = 0; i < pot3.length; i++) {
      if (pot3[i].token === token3) { gone = true; break; }
    }
    if (!gone) { return Response.json({ error: "not found" }, { status: 404 }); }
    await store.delete(PREFIX + token3);
    return Response.json({ ok: true });
  }

  return Response.json({ error: "method not allowed" }, { status: 405 });
}
