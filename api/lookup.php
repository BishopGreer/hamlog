<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

session_start_hamlog();
$user = current_user();
if (!$user) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }

header('Content-Type: application/json');

$call = strtoupper(trim($_GET['call'] ?? ''));
if (strlen($call) < 3) { echo json_encode([]); exit; }

// Check our own log for past QSOs with this call (cheapest lookup)
$st = db()->prepare(
    'SELECT name, qth, gridsquare, country, dxcc, cqz, ituz, cont
     FROM qsos q
     JOIN stations s ON s.id = q.station_id
     WHERE q.`call` = ? AND s.owner_id = ?
     ORDER BY q.date_on DESC, q.time_on DESC LIMIT 1'
);
$st->execute([$call, $user['id']]);
$local = $st->fetch();
if ($local && ($local['name'] || $local['qth'])) {
    echo json_encode([
        'call'    => $call,
        'name'    => $local['name'] ?? '',
        'qth'     => $local['qth'] ?? '',
        'grid'    => $local['gridsquare'] ?? '',
        'country' => $local['country'] ?? '',
        'dxcc'    => $local['dxcc'] ?? null,
        'cqz'     => $local['cqz'] ?? null,
        'ituz'    => $local['ituz'] ?? null,
        'cont'    => $local['cont'] ?? '',
        'source'  => 'local',
    ]);
    exit;
}

// QRZ XML lookup (requires API key)
$qrz_key = db_setting('qrz_api_key');
if (CALLSIGN_LOOKUP === 'qrz' && $qrz_key) {
    $url = "https://xmldata.qrz.com/xml/current/?s=$qrz_key;callsign=$call";
    $xml = @simplexml_load_file($url);
    if ($xml && isset($xml->Callsign)) {
        $c = $xml->Callsign;
        echo json_encode([
            'call'    => $call,
            'name'    => trim(($c->fname ?? '') . ' ' . ($c->name ?? '')),
            'qth'     => (string)($c->addr2 ?? ''),
            'grid'    => (string)($c->grid ?? ''),
            'country' => (string)($c->country ?? ''),
            'dxcc'    => isset($c->dxcc) ? (int)$c->dxcc : null,
            'cqz'     => isset($c->cqzone) ? (int)$c->cqzone : null,
            'ituz'    => isset($c->ituzone) ? (int)$c->ituzone : null,
            'cont'    => (string)($c->continent ?? ''),
            'source'  => 'qrz',
        ]);
        exit;
    }
}

// HamQTH lookup
$hamqth_key = db_setting('hamqth_api_key');
if (CALLSIGN_LOOKUP === 'hamqth' && $hamqth_key) {
    $url = "https://www.hamqth.com/xml.php?id=$hamqth_key&callsign=$call&prg=hamlog";
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
            'cont'    => (string)($s->continent ?? ''),
            'source'  => 'hamqth',
        ]);
        exit;
    }
}

echo json_encode(['call' => $call, 'source' => 'none']);
