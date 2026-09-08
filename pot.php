<?php
// pot.php — the shared "Join the pot" backend for Hack Hive Hackathon 01.
//
//   GET    -> { "pot": [names...], "matched": {...}|null, "matchAt": <ms> }
//   POST   -> { "name": ..., "code": ... }   returns { "ok": true, "token": ... }
//   DELETE -> { "token": ... }               returns { "ok": true }
//
// Storage is a JSON file kept OUTSIDE the web root, so it is never
// downloadable and needs no database setup:
//     <home>/pot_data/pot.json     (created automatically on first request)

header('Content-Type: application/json; charset=utf-8');

$ACCESS_CODE = 'HITSZ2025';   // must match LOCAL_FALLBACK_CODE in the page
$SEED        = 20250401;      // must match the shuffle seed used by the page

// ---- storage: one folder above public_html (never web-accessible) ----------
$DATA_DIR  = dirname(__FILE__) . '/../pot_data';
$DATA_FILE = $DATA_DIR . '/pot.json';

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function ensure_store() {
    global $DATA_DIR, $DATA_FILE;
    if (!is_dir($DATA_DIR)) {
        if (!@mkdir($DATA_DIR, 0775, true)) {
            respond(array('error' => 'pot temporarily unavailable'), 503);
        }
    }
    if (!is_file($DATA_FILE)) {
        $fp = @fopen($DATA_FILE, 'x');          // 'x' = create only (race-safe)
        if ($fp) { fwrite($fp, '[]'); fclose($fp); }
    }
}

function load_entries() {
    ensure_store();
    $fp = @fopen($DATA_FILE, 'r');
    if (!$fp || !flock($fp, LOCK_SH)) {
        respond(array('error' => 'pot temporarily unavailable'), 503);
    }
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $arr = json_decode($raw, true);
    return is_array($arr) ? $arr : array();
}

// read-modify-write under one exclusive lock so two signups can't collide
function update_entries($fn) {
    global $DATA_FILE;
    ensure_store();
    $fp = @fopen($DATA_FILE, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        respond(array('error' => 'pot temporarily unavailable'), 503);
    }
    $raw = stream_get_contents($fp);
    $arr = json_decode($raw, true);
    if (!is_array($arr)) { $arr = array(); }
    $arr = $fn($arr);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode(array_values($arr)));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function names_from_entries($entries) {
    $byName = array();
    foreach ($entries as $e) { $byName[] = $e; }
    usort($byName, function ($a, $b) {
        $c = $a['created_at'] - $b['created_at'];
        if ($c === 0) { return strcasecmp($a['name'], $b['name']); }
        return $c;
    });
    $names = array();
    foreach ($byName as $e) { $names[] = $e['name']; }
    return $names;
}

// Matching happens Thursday 23:00 Shenzhen time (same rule as the page).
// Computed in UTC integers to mirror the original function exactly.
function match_at_ms() {
    $nowMs = (int) round(microtime(true) * 1000);
    $s     = $nowMs + 8 * 3600000;               // pretend we are in UTC+8
    $sec   = intdiv($s, 1000);
    $w     = (int) gmdate('w', $sec);            // day of week, 0 = Sunday
    $diff  = (5 - $w + 7) % 7;                   // days until Friday
    if ($diff === 0) { $diff = 7; }
    $base = gmmktime(0, 0, 0, (int) gmdate('n', $sec), (int) gmdate('j', $sec) + $diff, (int) gmdate('Y', $sec));
    return $base * 1000 - 9 * 3600000;
}

function is_locked() {
    return match_at_ms() - (int) round(microtime(true) * 1000) <= 0;
}

// Same deterministic shuffle as the page (Park-Miller LCG).
function seeded_shuffle($names, $seed) {
    $a = array_values($names);
    $t = $seed % 2147483647;
    if ($t === 0) { $t = 1; }
    for ($i = count($a) - 1; $i > 0; $i--) {
        $t = ($t * 48271) % 2147483647;
        $r = $t / 2147483647;
        $j = (int) floor($r * ($i + 1));
        $tmp = $a[$i]; $a[$i] = $a[$j]; $a[$j] = $tmp;
    }
    return $a;
}

function build_matched($names) {
    if (!is_locked()) { return null; }
    $s = seeded_shuffle($names, $GLOBALS['SEED']);
    $m = array();
    for ($i = 0; $i + 1 < count($s); $i += 2) {
        $m[$s[$i]]     = $s[$i + 1];
        $m[$s[$i + 1]] = $s[$i];
    }
    if (count($s) % 2 === 1) { $m[$s[count($s) - 1]] = null; }
    return (object) $m;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') { http_response_code(200); exit; }

if ($method === 'GET') {
    $names = names_from_entries(load_entries());
    respond(array(
        'pot'     => $names,
        'matched' => build_matched($names),
        'matchAt' => match_at_ms()
    ));
}

if ($method === 'POST') {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($body)) { $body = array(); }

    $name = trim(isset($body['name']) ? (string) $body['name'] : '');
    $code = isset($body['code']) ? (string) $body['code'] : '';
    if (function_exists('mb_substr')) {
        $name = mb_substr($name, 0, 40);
    } else {
        $name = substr($name, 0, 40);
    }
    if ($name === '') { respond(array('error' => 'write your name first'), 400); }
    if ($code !== $ACCESS_CODE) { respond(array('error' => 'wrong code'), 403); }
    if (is_locked()) { respond(array('error' => 'too late, teams are locked'), 403); }

    $token = bin2hex(random_bytes(16));
    $at    = (int) round(microtime(true) * 1000);
    update_entries(function ($arr) use ($name, $token, $at) {
        foreach ($arr as $e) {
            if (strcasecmp($e['name'], $name) === 0) {
                respond(array('error' => 'already in the pot'), 409);
            }
        }
        $arr[] = array('name' => $name, 'token' => $token, 'created_at' => $at);
        return $arr;
    });
    respond(array('ok' => true, 'token' => $token));
}

if ($method === 'DELETE') {
    if (is_locked()) { respond(array('error' => 'too late, teams are locked'), 403); }

    $raw  = file_get_contents('php://input');
    $body = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($body)) { $body = array(); }
    $token = isset($body['token']) ? (string) $body['token'] : '';
    if ($token === '') { respond(array('error' => 'not found'), 404); }

    $found = false;
    update_entries(function ($arr) use ($token, &$found) {
        $out = array();
        foreach ($arr as $e) {
            if ($e['token'] === $token) { $found = true; continue; }
            $out[] = $e;
        }
        if (!$found) { respond(array('error' => 'not found'), 404); }
        return $out;
    });
    respond(array('ok' => true));
}

respond(array('error' => 'method not allowed'), 405);
