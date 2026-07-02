<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/qrz.php';
require_once __DIR__ . '/../includes/hamqth.php';

session_start_hamlog();
$user = current_user();
if (!$user) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

$call = strtoupper(trim($_GET['call'] ?? ''));
if (strlen($call) < 3) { echo json_encode([]); exit; }

// Resolve the base callsign for external lookups:
//   W1AW/P → W1AW  (suffix form — strip after slash)
//   W3/HA0ML → HA0ML  (prefix form — use part after slash)
$base_call = qrz_base_call($call);

$pdo = db();

// 1. Check our own log — try exact call first, then base call
$st = $pdo->prepare(
    'SELECT q.`name`, q.qth, q.gridsquare, q.country, q.dxcc, q.cqz, q.ituz, q.cont
     FROM qsos q
     JOIN stations s ON s.id = q.station_id
     WHERE q.`call` IN (?, ?) AND s.owner_id = ?
       AND (q.`name` IS NOT NULL OR q.country IS NOT NULL)
     ORDER BY (q.`call` = ?) DESC, q.date_on DESC, q.time_on DESC LIMIT 1'
);
$st->execute([$call, $base_call, $user['id'], $call]);
$local = $st->fetch();
if ($local) {
    echo json_encode([
        'call'    => $call,
        'name'    => $local['name'] ?? '',
        'qth'     => $local['qth'] ?? '',
        'grid'    => $local['gridsquare'] ?? '',
        'country' => $local['country'] ?? '',
        'dxcc'    => $local['dxcc'],
        'cqz'     => $local['cqz'],
        'ituz'    => $local['ituz'],
        'cont'    => $local['cont'] ?? '',
        'source'  => 'local',
    ]);
    exit;
}

// 2. QRZ XML lookup — always use base call (QRZ cannot resolve /P, /M, etc.)
if (qrz_configured()) {
    $data = qrz_lookup($base_call, $pdo);
    if ($data) {
        echo json_encode($data);
        exit;
    }
}

// 3. HamQTH fallback — also use base call
if (hamqth_configured()) {
    $data = hamqth_lookup($base_call, $pdo);
    if ($data) {
        echo json_encode($data);
        exit;
    }
}

echo json_encode(['call' => $call, 'source' => 'none']);
