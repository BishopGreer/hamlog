<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/qrz.php';

session_start_hamlog();
$user = current_user();
if (!$user) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

$call = strtoupper(trim($_GET['call'] ?? ''));
if (strlen($call) < 3) { echo json_encode([]); exit; }

$pdo = db();

// 1. Check our own log for past QSOs with this call — free and instant
$st = $pdo->prepare(
    'SELECT q.`name`, q.qth, q.gridsquare, q.country, q.dxcc, q.cqz, q.ituz, q.cont
     FROM qsos q
     JOIN stations s ON s.id = q.station_id
     WHERE q.`call` = ? AND s.owner_id = ?
       AND (q.`name` IS NOT NULL OR q.country IS NOT NULL)
     ORDER BY q.date_on DESC, q.time_on DESC LIMIT 1'
);
$st->execute([$call, $user['id']]);
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

// 2. QRZ XML lookup (requires QRZ Logbook Data subscription for full fields)
if (qrz_configured()) {
    $data = qrz_lookup($call, $pdo);
    if ($data) {
        echo json_encode($data);
        exit;
    }
}

// 3. HamQTH fallback
$hamqth_key = db_setting('hamqth_api_key');
if ($hamqth_key) {
    $url = 'https://www.hamqth.com/xml.php?id=' . urlencode($hamqth_key)
         . '&callsign=' . urlencode($call) . '&prg=hamlog';
    libxml_use_internal_errors(true);
    $xml = @simplexml_load_file($url);
    if ($xml && isset($xml->search)) {
        $s = $xml->search;
        echo json_encode([
            'call'    => $call,
            'name'    => (string)($s->nick ?? ''),
            'qth'     => (string)($s->qth ?? ''),
            'grid'    => (string)($s->grid ?? ''),
            'country' => (string)($s->country ?? ''),
            'dxcc'    => isset($s->adif) ? (int)$s->adif : null,
            'cqz'     => null,
            'ituz'    => null,
            'cont'    => (string)($s->continent ?? ''),
            'source'  => 'hamqth',
        ]);
        exit;
    }
}

echo json_encode(['call' => $call, 'source' => 'none']);
