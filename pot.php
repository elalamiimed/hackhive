<?php
// pot.php — the shared "Join the pot" backend for the HITSZCS Study Buddy
// hackathon. A MySQL replacement for the old Netlify function, so the same
// front-end page keeps working on cPanel shared hosting.
//
// Same API and same behaviour as the Netlify version:
//   GET    -> { "pot": [names...], "matched": {...}|null, "matchAt": <ms> }
//   POST   -> { "name": ..., "code": ... }   returns { "ok": true, "token": ... }
//   DELETE -> { "token": ... }               returns { "ok": true }
//
// The database password is NOT stored in this file or in git. pot.php reads
// it from a small config file that lives OUTSIDE the website folder:
//   /home/hitsfmec/pot_config.php
// (Create that file by following the setup steps; this repo stays safe.)

header('Content-Type: application/json; charset=utf-8');

$ACCESS_CODE = 'HITSZ2025';   // must match LOCAL_FALLBACK_CODE in study-buddy.html
$SEED        = 20250401;      // must match the shuffle seed used by the page

// ---- config lives one folder above public_html (never in the repo) --------
$configFile = dirname(__FILE__) . '/../pot_config.php';
if (!is_file($configFile)) {
    respond(array('error' => 'pot not configured yet — pot_config.php is missing'), 503);
}
$cfg = require $configFile;

function db() {
    global $cfg;
    try {
        $pdo = new PDO(
            'mysql:host=' . $cfg['db_host'] . ';dbname=' . $cfg['db_name'] . ';charset=utf8mb4',
            $cfg['db_user'],
            $cfg['db_pass'],
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
        );
        return $pdo;
    } catch (Exception $e) {
        respond(array('error' => 'pot temporarily unavailable'), 503);
    }
}

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// Matching happens Thursday 23:00 Shenzhen time (same rule as the page).
// Computed in UTC integers to mirror the old function exactly.
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

// Same deterministic shuffle as the old function (Park-Miller LCG).
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

function read_members($pdo) {
    $rows = $pdo->query('SELECT name, token FROM pot_members ORDER BY created_at ASC, name ASC')->fetchAll(PDO::FETCH_ASSOC);
    $names = array();
    foreach ($rows as $r) { $names[] = $r['name']; }
    return array('names' => $names, 'rows' => $rows);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') { http_response_code(200); exit; }

if ($method === 'GET') {
    try {
        $pdo    = db();
        $data   = read_members($pdo);
        respond(array(
            'pot'     => $data['names'],
            'matched' => build_matched($data['names']),
            'matchAt' => match_at_ms()
        ));
    } catch (Exception $e) {
        respond(array('error' => 'pot temporarily unavailable'), 503);
    }
}

if ($method === 'POST') {
    try {
        $pdo = db();
    } catch (Exception $e) {
        respond(array('error' => 'pot temporarily unavailable'), 503);
    }

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

    $st = $pdo->prepare('SELECT id FROM pot_members WHERE LOWER(name) = LOWER(?) LIMIT 1');
    $st->execute(array($name));
    if ($st->fetch()) { respond(array('error' => 'already in the pot'), 409); }

    $token = bin2hex(random_bytes(16));
    $at    = (int) round(microtime(true) * 1000);
    $ins   = $pdo->prepare('INSERT INTO pot_members (name, token, created_at) VALUES (?, ?, ?)');
    $ins->execute(array($name, $token, $at));
    respond(array('ok' => true, 'token' => $token));
}

if ($method === 'DELETE') {
    try {
        $pdo = db();
    } catch (Exception $e) {
        respond(array('error' => 'pot temporarily unavailable'), 503);
    }
    if (is_locked()) { respond(array('error' => 'too late, teams are locked'), 403); }

    $raw  = file_get_contents('php://input');
    $body = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($body)) { $body = array(); }
    $token = isset($body['token']) ? (string) $body['token'] : '';
    if ($token === '') { respond(array('error' => 'not found'), 404); }

    $chk = $pdo->prepare('SELECT id FROM pot_members WHERE token = ? LIMIT 1');
    $chk->execute(array($token));
    if (!$chk->fetch()) { respond(array('error' => 'not found'), 404); }

    $del = $pdo->prepare('DELETE FROM pot_members WHERE token = ?');
    $del->execute(array($token));
    respond(array('ok' => true));
}

respond(array('error' => 'method not allowed'), 405);
