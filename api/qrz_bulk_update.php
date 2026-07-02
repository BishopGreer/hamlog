<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/qrz.php';

session_start_hamlog();
$user = require_login();

header('Content-Type: application/json');

$pdo    = db();
$action = $_GET['action'] ?? 'update';
$sid    = (int)($_GET['station_id'] ?? 0);
$force  = !empty($_GET['force']);
$offset = max(0, (int)($_GET['offset'] ?? 0));
$batch  = min(10, max(1, (int)($_GET['batch'] ?? 5)));

if (!$sid || !user_can_access_station($user['id'], $sid)) {
    echo json_encode(['error' => 'Invalid or inaccessible station']); exit;
}

if (!qrz_configured()) {
    echo json_encode(['error' => 'QRZ credentials not configured — go to Admin → QRZ']); exit;
}

// ── Count unique callsigns needing update ─────────────────────────────────────
if ($action === 'count') {
    if ($force) {
        $st = $pdo->prepare('SELECT COUNT(DISTINCT `call`) FROM qsos WHERE station_id = ?');
        $st->execute([$sid]);
    } else {
        $st = $pdo->prepare(
            'SELECT COUNT(DISTINCT `call`) FROM qsos
             WHERE station_id = ? AND (`name` IS NULL OR country IS NULL OR gridsquare IS NULL)'
        );
        $st->execute([$sid]);
    }
    echo json_encode(['total' => (int)$st->fetchColumn()]);
    exit;
}

// ── Process a batch ───────────────────────────────────────────────────────────
if ($force) {
    $st = $pdo->prepare(
        'SELECT DISTINCT `call` FROM qsos WHERE station_id = ? ORDER BY `call` LIMIT ? OFFSET ?'
    );
} else {
    $st = $pdo->prepare(
        'SELECT DISTINCT `call` FROM qsos WHERE station_id = ?
         AND (`name` IS NULL OR country IS NULL OR gridsquare IS NULL)
         ORDER BY `call` LIMIT ? OFFSET ?'
    );
}
$st->execute([$sid, $batch, $offset]);
$calls = array_column($st->fetchAll(), 'call');

$updated  = 0;
$notfound = 0;
$errors   = 0;
$log      = [];

foreach ($calls as $call) {
    $base_call = strstr($call, '/', true) ?: $call;
    $data = qrz_lookup($base_call, $pdo);

    if ($data === null) {
        $errors++;
        $log[] = ['call' => $call, 'status' => 'error'];
        usleep(200000);
        continue;
    }

    if (empty($data['name']) && empty($data['country'])) {
        $notfound++;
        $log[] = ['call' => $call, 'status' => 'notfound'];
        usleep(200000);
        continue;
    }

    // Non-force: COALESCE preserves existing values (existing wins).
    // Force:     COALESCE reversed — QRZ wins when it has data.
    if ($force) {
        $pdo->prepare(
            'UPDATE qsos SET
               `name`     = COALESCE(NULLIF(?, \'\'), `name`),
               qth        = COALESCE(NULLIF(?, \'\'), qth),
               country    = COALESCE(NULLIF(?, \'\'), country),
               gridsquare = COALESCE(NULLIF(?, \'\'), gridsquare),
               dxcc       = COALESCE(?, dxcc),
               cqz        = COALESCE(?, cqz),
               ituz       = COALESCE(?, ituz),
               cont       = COALESCE(NULLIF(?, \'\'), cont)
             WHERE station_id = ? AND `call` = ?'
        )->execute([
            $data['name']    ?? '',
            $data['qth']     ?? '',
            $data['country'] ?? '',
            $data['grid']    ?? '',
            $data['dxcc'],
            $data['cqz'],
            $data['ituz'],
            $data['cont']    ?? '',
            $sid, $call,
        ]);
    } else {
        $pdo->prepare(
            'UPDATE qsos SET
               `name`     = COALESCE(`name`,     NULLIF(?, \'\')),
               qth        = COALESCE(qth,         NULLIF(?, \'\')),
               country    = COALESCE(country,     NULLIF(?, \'\')),
               gridsquare = COALESCE(gridsquare,  NULLIF(?, \'\')),
               dxcc       = COALESCE(dxcc,        ?),
               cqz        = COALESCE(cqz,         ?),
               ituz       = COALESCE(ituz,         ?),
               cont       = COALESCE(cont,         NULLIF(?, \'\'))
             WHERE station_id = ? AND `call` = ?'
        )->execute([
            $data['name']    ?? '',
            $data['qth']     ?? '',
            $data['country'] ?? '',
            $data['grid']    ?? '',
            $data['dxcc'],
            $data['cqz'],
            $data['ituz'],
            $data['cont']    ?? '',
            $sid, $call,
        ]);
    }

    $updated++;
    $log[] = ['call' => $call, 'status' => 'updated', 'name' => $data['name'] ?? '', 'country' => $data['country'] ?? ''];
    usleep(200000); // 200 ms between QRZ requests — polite rate limit
}

echo json_encode([
    'processed' => count($calls),
    'updated'   => $updated,
    'notfound'  => $notfound,
    'errors'    => $errors,
    'log'       => $log,
]);
