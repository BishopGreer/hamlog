<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

session_start_hamlog();
$user = require_login();
$pdo  = db();

$stations = get_user_stations($user['id']);
$active_sid = (int)($_SESSION['active_station'] ?? ($stations[0]['id'] ?? 0));

// EXPORT
if (isset($_GET['export'])) {
    $sid = (int)($_GET['station'] ?? $active_sid);
    if (!user_can_access_station($user['id'], $sid)) { http_response_code(403); exit; }
    $st = $pdo->prepare('SELECT callsign FROM stations WHERE id = ?');
    $st->execute([$sid]);
    $call = $st->fetchColumn() ?: 'UNKNOWN';
    $st = $pdo->prepare('SELECT * FROM qsos WHERE station_id = ? ORDER BY date_on, time_on');
    $st->execute([$sid]);
    $qsos = $st->fetchAll();
    $adif = export_adif($qsos, $call);
    $fname = strtolower($call) . '_' . date('Ymd') . '.adi';
    header('Content-Type: text/plain; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"$fname\"");
    echo $adif;
    exit;
}

// IMPORT POST
$import_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['adif_file'])) {
    $sid = (int)($_POST['station_id'] ?? $active_sid);
    if (!user_can_access_station($user['id'], $sid)) {
        flash('error', 'Access denied.');
        redirect('/adif.php');
    }
    $file = $_FILES['adif_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        flash('error', 'File upload error.');
        redirect('/adif.php');
    }
    if ($file['size'] > 20 * 1024 * 1024) {
        flash('error', 'File too large (max 20 MB).');
        redirect('/adif.php');
    }
    $content = file_get_contents($file['tmp_name']);
    if ($content === false) {
        flash('error', 'Could not read uploaded file.');
        redirect('/adif.php');
    }
    $records = parse_adif($content);

    try {
        // Resolve logbook
        $lb_st = $pdo->prepare('SELECT id FROM logbooks WHERE station_id = ? ORDER BY is_default DESC LIMIT 1');
        $lb_st->execute([$sid]);
        $logbook_id = (int)$lb_st->fetchColumn();
        if (!$logbook_id) {
            $ins = $pdo->prepare('INSERT INTO logbooks (station_id, name, is_default) VALUES (?,?,1)');
            $ins->execute([$sid, 'Main Log']);
            $logbook_id = (int)$pdo->lastInsertId();
        }

        $skip_dups = isset($_POST['skip_duplicates']);
        $imported = $skipped = $errors = 0;

        $insert_st = $pdo->prepare(
            'INSERT INTO qsos (logbook_id, station_id, `call`, date_on, time_on, band, freq, `mode`, submode,
             rst_sent, rst_rcvd, `name`, qth, gridsquare, dxcc, country, cont, ituz, cqz, iota, tx_pwr,
             `comment`, notes, lotw_qsl_sent, lotw_qsl_rcvd, eqsl_qsl_sent, eqsl_qsl_rcvd, qsl_sent, qsl_rcvd)
             VALUES
             (:logbook_id, :station_id, :call, :date_on, :time_on, :band, :freq, :mode, :submode,
             :rst_sent, :rst_rcvd, :name, :qth, :gridsquare, :dxcc, :country, :cont, :ituz, :cqz, :iota, :tx_pwr,
             :comment, :notes, :lotw_qsl_sent, :lotw_qsl_rcvd, :eqsl_qsl_sent, :eqsl_qsl_rcvd, :qsl_sent, :qsl_rcvd)'
        );

        $pdo->beginTransaction();
        foreach ($records as $r) {
            $row = adif_to_qso($r);
            if (empty($row['call']) || empty($row['date_on'])) { $errors++; continue; }
            if ($skip_dups && is_duplicate_qso($sid, $row['call'], $row['date_on'], $row['time_on'], $row['band'] ?? '', $row['mode'])) {
                $skipped++; continue;
            }
            $row['logbook_id'] = $logbook_id;
            $row['station_id'] = $sid;
            $insert_st->execute($row);
            $imported++;
        }
        $pdo->commit();

        // Log upload record
        $st = $pdo->prepare('INSERT INTO uploads (station_id, user_id, type, filename, qso_count, status) VALUES (?,?,?,?,?,?)');
        $st->execute([$sid, $user['id'], 'adif_import', $file['name'], $imported, 'success']);
        flash('success', "Import complete: $imported imported, $skipped skipped, $errors errors.");
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', 'Import failed: ' . $e->getMessage());
    }
    redirect('/adif.php');
}

$logbooks = $active_sid ? get_station_logbooks($active_sid) : [];
$page_title = 'ADIF Import / Export';
include __DIR__ . '/includes/header.php';
?>

<div class="row g-4">
  <!-- Import -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-file-earmark-arrow-up"></i> Import ADIF</div>
      <div class="card-body">
        <p class="text-muted small">Import contacts from any ADIF-compatible logger (WSJT-X, Log4OM, Ham Radio Deluxe, N1MM, etc.)</p>
        <form method="post" enctype="multipart/form-data">
          <div class="mb-3">
            <label class="form-label">Station</label>
            <select name="station_id" class="form-select">
              <?php foreach ($stations as $s): ?>
              <option value="<?= $s['id'] ?>" <?= $s['id'] == $active_sid ? 'selected' : '' ?>>
                <?= h($s['callsign']) ?><?= $s['is_club_station'] ? ' [Club]' : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">ADIF File (.adi / .adif)</label>
            <input type="file" name="adif_file" class="form-control" accept=".adi,.adif,.txt" required>
          </div>
          <div class="mb-3 form-check">
            <input type="checkbox" class="form-check-input" name="skip_duplicates" id="skip_dup" checked>
            <label class="form-check-label" for="skip_dup">Skip duplicate QSOs (same call, band, mode within 2 min)</label>
          </div>
          <button type="submit" class="btn btn-success">
            <i class="bi bi-upload"></i> Import
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- Export -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-file-earmark-arrow-down"></i> Export ADIF</div>
      <div class="card-body">
        <p class="text-muted small">Export your entire log as an ADIF file compatible with all logging software and online services.</p>
        <div class="mb-3">
          <label class="form-label">Station to Export</label>
          <select id="export-station" class="form-select">
            <?php foreach ($stations as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $s['id'] == $active_sid ? 'selected' : '' ?>>
              <?= h($s['callsign']) ?><?= $s['is_club_station'] ? ' [Club]' : '' ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <a id="export-btn" href="<?= BASE_URL ?>/adif.php?export=1&station=<?= $active_sid ?>"
           class="btn btn-outline-success">
          <i class="bi bi-download"></i> Download ADIF
        </a>
        <script>
        document.getElementById('export-station').addEventListener('change', function() {
            document.getElementById('export-btn').href =
                '<?= BASE_URL ?>/adif.php?export=1&station=' + this.value;
        });
        </script>

        <hr class="border-secondary mt-4">
        <p class="text-muted small mb-1"><strong>LoTW Export</strong> — upload the .adi file to <a href="https://lotw.arrl.org" target="_blank" class="text-success">LoTW</a> after signing with tQSL.</p>
        <p class="text-muted small mb-0"><strong>ClubLog / eQSL</strong> — use the <a href="<?= BASE_URL ?>/upload.php" class="text-success">Upload Center</a> for direct API uploads.</p>
      </div>
    </div>
  </div>

  <!-- Upload history -->
  <div class="col-12">
    <div class="card">
      <div class="card-header"><i class="bi bi-clock-history"></i> Import History</div>
      <div class="card-body p-0">
        <?php
        $st = $pdo->prepare(
            "SELECT u.*, s.callsign FROM uploads u
             JOIN stations s ON s.id = u.station_id
             WHERE u.user_id = ? AND u.type = 'adif_import'
             ORDER BY u.created_at DESC LIMIT 20"
        );
        $st->execute([$user['id']]);
        $history = $st->fetchAll();
        ?>
        <?php if (empty($history)): ?>
        <p class="text-muted text-center py-3">No imports yet.</p>
        <?php else: ?>
        <table class="table table-sm mb-0">
          <thead><tr><th>Date</th><th>Station</th><th>File</th><th>QSOs</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($history as $h): ?>
            <tr>
              <td class="text-muted"><?= h(substr($h['created_at'],0,16)) ?></td>
              <td><span class="callsign"><?= h($h['callsign']) ?></span></td>
              <td><?= h($h['filename'] ?? '') ?></td>
              <td><?= number_format($h['qso_count']) ?></td>
              <td><span class="badge bg-success"><?= h($h['status']) ?></span></td>
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
