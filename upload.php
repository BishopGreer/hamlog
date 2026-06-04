<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

session_start_hamlog();
$user = require_login();
$pdo  = db();

$stations   = get_user_stations($user['id']);
$active_sid = (int)($_SESSION['active_station'] ?? ($stations[0]['id'] ?? 0));
$service    = $_GET['service'] ?? 'lotw';
if (!in_array($service, ['lotw','eqsl','clublog'])) $service = 'lotw';

$station = null;
if ($active_sid) {
    $st = $pdo->prepare('SELECT * FROM stations WHERE id = ?');
    $st->execute([$active_sid]);
    $station = $st->fetch();
}

// LoTW upload — just export ADIF for manual signing with tQSL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $service === 'lotw') {
    $sid = (int)($_POST['station_id'] ?? $active_sid);
    $since = $_POST['since'] ?? '';
    if (!user_can_access_station($user['id'], $sid)) { flash('error','Access denied.'); redirect('/upload.php?service=lotw'); }
    $st2 = $pdo->prepare('SELECT callsign FROM stations WHERE id = ?'); $st2->execute([$sid]); $call = $st2->fetchColumn();
    $where = 'station_id = ?';
    $params = [$sid];
    if ($since) { $where .= ' AND date_on >= ?'; $params[] = $since; }
    $st2 = $pdo->prepare("SELECT * FROM qsos WHERE $where ORDER BY date_on, time_on");
    $st2->execute($params);
    $qsos = $st2->fetchAll();
    $adif = export_adif($qsos, $call);
    $fname = strtolower($call) . '_lotw_' . date('Ymd') . '.adi';
    // Mark as queued
    $upd = $pdo->prepare("UPDATE qsos SET lotw_qsl_sent='Q' WHERE $where AND lotw_qsl_sent='N'");
    $upd->execute($params);
    $pdo->prepare('INSERT INTO uploads (station_id,user_id,type,filename,qso_count,status) VALUES (?,?,?,?,?,?)')->execute([$sid,$user['id'],'lotw',$fname,count($qsos),'success']);
    header('Content-Type: text/plain; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"$fname\"");
    echo $adif;
    exit;
}

// eQSL upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $service === 'eqsl') {
    $sid     = (int)($_POST['station_id'] ?? $active_sid);
    $eqsl_u  = trim($_POST['eqsl_username'] ?? '');
    $eqsl_p  = trim($_POST['eqsl_password'] ?? '');
    if (!user_can_access_station($user['id'], $sid)) { flash('error','Access denied.'); redirect('/upload.php?service=eqsl'); }
    if (empty($eqsl_u) || empty($eqsl_p)) { flash('error','eQSL username and password are required.'); redirect('/upload.php?service=eqsl'); }
    $st2 = $pdo->prepare('SELECT callsign FROM stations WHERE id = ?'); $st2->execute([$sid]); $call = $st2->fetchColumn();
    $st2 = $pdo->prepare("SELECT * FROM qsos WHERE station_id = ? AND eqsl_qsl_sent='N' ORDER BY date_on, time_on");
    $st2->execute([$sid]);
    $qsos = $st2->fetchAll();
    if (empty($qsos)) { flash('warning','No unsent QSOs for eQSL.'); redirect('/upload.php?service=eqsl'); }
    $adif = export_adif($qsos, $call);
    // POST to eQSL
    $url = 'https://www.eqsl.cc/qslcard/ImportADIF.cfm';
    $post = ['ADIFData' => $adif, 'EQSL_USER' => $eqsl_u, 'EQSL_PSWD' => $eqsl_p, 'EQSL_CALL' => $call];
    $ctx = stream_context_create(['http'=>['method'=>'POST','header'=>'Content-Type: application/x-www-form-urlencoded','content'=>http_build_query($post),'timeout'=>30]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp !== false && stripos($resp, 'Error:') === false) {
        $pdo->prepare("UPDATE qsos SET eqsl_qsl_sent='Y' WHERE station_id = ? AND eqsl_qsl_sent='N'")->execute([$sid]);
        $pdo->prepare('INSERT INTO uploads (station_id,user_id,type,filename,qso_count,status) VALUES (?,?,?,?,?,?)')->execute([$sid,$user['id'],'eqsl','eqsl_upload',count($qsos),'success']);
        flash('success', count($qsos) . ' QSOs uploaded to eQSL.');
    } else {
        flash('error', 'eQSL upload failed. Check credentials. Response: ' . htmlspecialchars(substr($resp ?? '', 0, 200)));
    }
    redirect('/upload.php?service=eqsl');
}

// ClubLog upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $service === 'clublog') {
    $sid    = (int)($_POST['station_id'] ?? $active_sid);
    $cl_u   = trim($_POST['clublog_email'] ?? '');
    $cl_p   = trim($_POST['clublog_password'] ?? '');
    $cl_app = trim($_POST['clublog_appkey'] ?? db_setting('clublog_app_key'));
    if (!user_can_access_station($user['id'], $sid)) { flash('error','Access denied.'); redirect('/upload.php?service=clublog'); }
    $st2 = $pdo->prepare('SELECT callsign FROM stations WHERE id = ?'); $st2->execute([$sid]); $call = $st2->fetchColumn();
    $st2 = $pdo->prepare("SELECT * FROM qsos WHERE station_id = ? AND clublog_upload_status='N' ORDER BY date_on, time_on");
    $st2->execute([$sid]);
    $qsos = $st2->fetchAll();
    if (empty($qsos)) { flash('warning','No unsent QSOs for ClubLog.'); redirect('/upload.php?service=clublog'); }
    $adif = export_adif($qsos, $call);
    $post = ['email'=>$cl_u,'password'=>$cl_p,'callsign'=>$call,'appkey'=>$cl_app,'adif'=>$adif];
    $ctx  = stream_context_create(['http'=>['method'=>'POST','header'=>'Content-Type: application/x-www-form-urlencoded','content'=>http_build_query($post),'timeout'=>30]]);
    $resp = @file_get_contents('https://clublog.org/realtime.php', false, $ctx);
    if ($resp !== false && str_contains($resp, 'OK')) {
        $pdo->prepare("UPDATE qsos SET clublog_upload_status='Y' WHERE station_id = ? AND clublog_upload_status='N'")->execute([$sid]);
        $pdo->prepare('INSERT INTO uploads (station_id,user_id,type,filename,qso_count,status) VALUES (?,?,?,?,?,?)')->execute([$sid,$user['id'],'clublog','clublog_upload',count($qsos),'success']);
        flash('success', count($qsos) . ' QSOs uploaded to ClubLog.');
    } else {
        flash('error', 'ClubLog upload failed. Response: ' . htmlspecialchars(substr($resp ?? '', 0, 200)));
    }
    redirect('/upload.php?service=clublog');
}

// Upload history
$st = $pdo->prepare(
    "SELECT u.*, s.callsign FROM uploads u
     JOIN stations s ON s.id = u.station_id
     WHERE u.user_id = ? AND u.type IN ('lotw','eqsl','clublog')
     ORDER BY u.created_at DESC LIMIT 30"
);
$st->execute([$user['id']]);
$history = $st->fetchAll();

$page_title = 'Upload Center';
include __DIR__ . '/includes/header.php';
?>

<div class="mb-3">
  <h4 class="mb-2 text-success"><i class="bi bi-cloud-upload"></i> Upload Center</h4>
  <ul class="nav nav-pills">
    <li class="nav-item">
      <a class="nav-link <?= $service==='lotw'?'active':'' ?>" href="?service=lotw">
        <i class="bi bi-check2-circle"></i> LoTW
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $service==='eqsl'?'active':'' ?>" href="?service=eqsl">
        <i class="bi bi-envelope-check"></i> eQSL
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $service==='clublog'?'active':'' ?>" href="?service=clublog">
        <i class="bi bi-bar-chart-line"></i> ClubLog
      </a>
    </li>
  </ul>
</div>

<div class="row g-4">
<div class="col-md-7">
<?php if ($service === 'lotw'): ?>
<div class="card">
  <div class="card-header"><i class="bi bi-check2-circle"></i> Logbook of The World (LoTW) — ARRL</div>
  <div class="card-body">
    <p class="text-muted small">LoTW requires signing your ADIF file with <strong>tQSL</strong> before uploading.
    Download the ADIF here, sign it with tQSL, then upload the signed <code>.tq8</code> file at
    <a href="https://lotw.arrl.org" target="_blank" class="text-success">lotw.arrl.org</a>.</p>
    <form method="post">
      <div class="mb-3">
        <label class="form-label">Station</label>
        <select name="station_id" class="form-select">
          <?php foreach ($stations as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $s['id']==$active_sid?'selected':'' ?>><?= h($s['callsign']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label">Export QSOs since (optional)</label>
        <input type="date" name="since" class="form-control">
      </div>
      <button type="submit" class="btn btn-success"><i class="bi bi-download"></i> Download ADIF for LoTW</button>
    </form>
  </div>
</div>

<?php elseif ($service === 'eqsl'): ?>
<div class="card">
  <div class="card-header"><i class="bi bi-envelope-check"></i> eQSL.cc</div>
  <div class="card-body">
    <p class="text-muted small">Upload all unsent QSOs directly to eQSL.cc using your account credentials.</p>
    <form method="post">
      <div class="mb-3">
        <label class="form-label">Station</label>
        <select name="station_id" class="form-select">
          <?php foreach ($stations as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $s['id']==$active_sid?'selected':'' ?>><?= h($s['callsign']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label">eQSL Username</label>
        <input type="text" name="eqsl_username" class="form-control" required>
      </div>
      <div class="mb-3">
        <label class="form-label">eQSL Password</label>
        <input type="password" name="eqsl_password" class="form-control" required>
      </div>
      <button type="submit" class="btn btn-success"><i class="bi bi-cloud-upload"></i> Upload to eQSL</button>
    </form>
  </div>
</div>

<?php elseif ($service === 'clublog'): ?>
<div class="card">
  <div class="card-header"><i class="bi bi-bar-chart-line"></i> ClubLog</div>
  <div class="card-body">
    <p class="text-muted small">Upload unsent QSOs to <a href="https://clublog.org" target="_blank" class="text-success">ClubLog</a> using real-time upload API.</p>
    <form method="post">
      <div class="mb-3">
        <label class="form-label">Station</label>
        <select name="station_id" class="form-select">
          <?php foreach ($stations as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $s['id']==$active_sid?'selected':'' ?>><?= h($s['callsign']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label">ClubLog Email</label>
        <input type="email" name="clublog_email" class="form-control" required>
      </div>
      <div class="mb-3">
        <label class="form-label">ClubLog Password</label>
        <input type="password" name="clublog_password" class="form-control" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Application Key</label>
        <input type="text" name="clublog_appkey" class="form-control"
               value="<?= h(db_setting('clublog_app_key')) ?>" placeholder="Register your app key at clublog.org">
      </div>
      <button type="submit" class="btn btn-success"><i class="bi bi-cloud-upload"></i> Upload to ClubLog</button>
    </form>
  </div>
</div>
<?php endif; ?>
</div>

<!-- Upload history -->
<div class="col-md-5">
  <div class="card">
    <div class="card-header"><i class="bi bi-clock-history"></i> Upload History</div>
    <div class="card-body p-0" style="max-height:400px;overflow-y:auto">
      <?php if (empty($history)): ?>
      <p class="text-muted text-center py-3">No uploads yet.</p>
      <?php else: ?>
      <table class="table table-sm mb-0">
        <thead><tr><th>Date</th><th>Station</th><th>Service</th><th>QSOs</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($history as $h): ?>
          <tr>
            <td class="text-muted" style="font-size:.75rem"><?= h(substr($h['created_at'],0,10)) ?></td>
            <td><span class="callsign" style="font-size:.8rem"><?= h($h['callsign']) ?></span></td>
            <td><span class="badge bg-secondary"><?= h(strtoupper($h['type'])) ?></span></td>
            <td><?= number_format($h['qso_count']) ?></td>
            <td><span class="badge <?= $h['status']==='success'?'bg-success':'bg-danger' ?>"><?= h($h['status']) ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
