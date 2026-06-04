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

// Editing existing QSO?
$edit_id  = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit_qso = null;
if ($edit_id) {
    $st = $pdo->prepare('SELECT * FROM qsos WHERE id = ? AND station_id = ?');
    $st->execute([$edit_id, $active_sid]);
    $edit_qso = $st->fetch() ?: null;
    if (!$edit_qso) { flash('error', 'QSO not found or access denied.'); redirect('/logbook.php'); }
}

// Handle quick-log from dashboard
if (isset($_POST['quick_log'])) {
    $_POST['date_on']  = utc_date();
    $_POST['time_on']  = utc_time();
    $_POST['logbook_id'] = 0; // will resolve below
}

// DELETE
if (isset($_POST['delete_qso']) && $edit_id) {
    $st = $pdo->prepare('DELETE FROM qsos WHERE id = ? AND station_id = ?');
    $st->execute([$edit_id, $active_sid]);
    flash('success', 'QSO deleted.');
    redirect('/logbook.php');
}

// SAVE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete_qso'])) {
    $sid = (int)($_POST['station_id'] ?? $active_sid);
    if (!user_can_access_station($user['id'], $sid)) {
        flash('error', 'Access denied to that station.');
        redirect('/log.php');
    }

    // Resolve logbook
    $logbook_id = (int)($_POST['logbook_id'] ?? 0);
    if (!$logbook_id) {
        $st = $pdo->prepare('SELECT id FROM logbooks WHERE station_id = ? ORDER BY is_default DESC LIMIT 1');
        $st->execute([$sid]);
        $logbook_id = (int)$st->fetchColumn();
        if (!$logbook_id) {
            // Create default logbook
            $st = $pdo->prepare('INSERT INTO logbooks (station_id, name, is_default) VALUES (?,?,1)');
            $st->execute([$sid, 'Main Log']);
            $logbook_id = (int)$pdo->lastInsertId();
        }
    }

    $call    = strtoupper(trim($_POST['call'] ?? ''));
    $date_on = trim($_POST['date_on'] ?? '');
    $time_on = trim($_POST['time_on'] ?? '') . ':00';
    $band    = trim($_POST['band'] ?? '');
    $freq    = isset($_POST['freq']) && $_POST['freq'] !== '' ? (float)$_POST['freq'] : null;
    $mode    = strtoupper(trim($_POST['mode'] ?? 'SSB'));

    if (empty($call) || empty($date_on) || empty($mode)) {
        flash('error', 'Call, date, and mode are required.');
        redirect('/log.php');
    }

    if (!$edit_id && is_duplicate_qso($sid, $call, $date_on, $time_on, $band, $mode)) {
        flash('warning', "Possible duplicate: $call on $band/$mode already in log near this time.");
    }

    $data = [
        'logbook_id'   => $logbook_id,
        'station_id'   => $sid,
        'call'         => $call,
        'date_on'      => $date_on,
        'time_on'      => $time_on,
        'band'         => $band ?: ($freq ? freq_to_band($freq) : null),
        'freq'         => $freq,
        'mode'         => $mode,
        'submode'      => strtoupper(trim($_POST['submode'] ?? '')) ?: null,
        'rst_sent'     => trim($_POST['rst_sent'] ?? '59'),
        'rst_rcvd'     => trim($_POST['rst_rcvd'] ?? '59'),
        'name'         => trim($_POST['name'] ?? '') ?: null,
        'qth'          => trim($_POST['qth'] ?? '') ?: null,
        'gridsquare'   => strtoupper(trim($_POST['gridsquare'] ?? '')) ?: null,
        'dxcc'         => isset($_POST['dxcc']) && $_POST['dxcc'] !== '' ? (int)$_POST['dxcc'] : null,
        'country'      => trim($_POST['country'] ?? '') ?: null,
        'cont'         => strtoupper(trim($_POST['cont'] ?? '')) ?: null,
        'ituz'         => isset($_POST['ituz']) && $_POST['ituz'] !== '' ? (int)$_POST['ituz'] : null,
        'cqz'          => isset($_POST['cqz']) && $_POST['cqz'] !== '' ? (int)$_POST['cqz'] : null,
        'iota'         => strtoupper(trim($_POST['iota'] ?? '')) ?: null,
        'tx_pwr'       => isset($_POST['tx_pwr']) && $_POST['tx_pwr'] !== '' ? (float)$_POST['tx_pwr'] : null,
        'comment'      => trim($_POST['comment'] ?? '') ?: null,
        'notes'        => trim($_POST['notes'] ?? '') ?: null,
        'lotw_qsl_sent'=> $_POST['lotw_qsl_sent'] ?? 'N',
        'lotw_qsl_rcvd'=> $_POST['lotw_qsl_rcvd'] ?? 'N',
        'eqsl_qsl_sent'=> $_POST['eqsl_qsl_sent'] ?? 'N',
        'eqsl_qsl_rcvd'=> $_POST['eqsl_qsl_rcvd'] ?? 'N',
        'qsl_sent'     => $_POST['qsl_sent'] ?? 'N',
        'qsl_rcvd'     => $_POST['qsl_rcvd'] ?? 'N',
    ];

    if ($edit_id) {
        $sets = implode(', ', array_map(fn($k) => "`$k` = :$k", array_keys($data)));
        $data['id'] = $edit_id;
        $pdo->prepare("UPDATE qsos SET $sets WHERE id = :id")->execute($data);
        flash('success', "QSO with $call updated.");
        redirect('/logbook.php');
    } else {
        $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($data)));
        $vals = implode(', ', array_map(fn($k) => ":$k", array_keys($data)));
        $pdo->prepare("INSERT INTO qsos ($cols) VALUES ($vals)")->execute($data);
        $new_id = (int)$pdo->lastInsertId();
        flash('success', "QSO with $call logged. <a href='" . BASE_URL . "/log.php?edit=$new_id' class='alert-link'>Edit</a>");
        redirect('/log.php');
    }
}

// Fetch logbooks for active station
$logbooks = $active_sid ? get_station_logbooks($active_sid) : [];
$page_title = $edit_qso ? 'Edit QSO' : 'Log QSO';
include __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
<div class="col-xl-9">
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-pencil-square"></i> <?= $edit_qso ? 'Edit QSO' : 'Log QSO' ?></span>
    <?php if ($edit_qso): ?>
    <span class="text-muted small">ID #<?= $edit_id ?></span>
    <?php endif; ?>
  </div>
  <div class="card-body">
  <form method="post" id="qso-form">
    <?php if ($edit_qso): ?>
    <input type="hidden" name="edit_id" value="<?= $edit_id ?>">
    <?php endif; ?>

    <!-- Station & logbook -->
    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <label class="form-label">Station</label>
        <select name="station_id" class="form-select" required>
          <?php foreach ($stations as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $s['id'] == $active_sid ? 'selected' : '' ?>>
            <?= h($s['callsign']) ?><?= $s['is_club_station'] ? ' [Club]' : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">Logbook</label>
        <select name="logbook_id" class="form-select">
          <?php foreach ($logbooks as $lb): ?>
          <option value="<?= $lb['id'] ?>" <?= ($edit_qso && $edit_qso['logbook_id'] == $lb['id']) ? 'selected' : '' ?>>
            <?= h($lb['name']) ?><?= $lb['is_default'] ? ' (Default)' : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <!-- Core fields -->
    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <label class="form-label">Callsign *</label>
        <input type="text" id="call" name="call" class="form-control" style="text-transform:uppercase;font-family:monospace;font-size:1.1rem;font-weight:bold"
               value="<?= h($edit_qso['call'] ?? '') ?>" required autofocus>
      </div>
      <div class="col-md-4">
        <label class="form-label">Date (UTC) *</label>
        <input type="date" id="date_on" name="date_on" class="form-control"
               value="<?= h($edit_qso['date_on'] ?? utc_date()) ?>" required>
      </div>
      <div class="col-md-4">
        <label class="form-label">Time (UTC) *</label>
        <input type="time" id="time_on" name="time_on" class="form-control"
               value="<?= h($edit_qso ? substr($edit_qso['time_on'],0,5) : utc_time()) ?>" required>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-3">
        <label class="form-label">Band</label>
        <select id="band" name="band" class="form-select">
          <option value="">— Auto —</option>
          <?php foreach (BANDS as $b => $_): ?>
          <option value="<?= $b ?>" <?= ($edit_qso['band'] ?? '') === $b ? 'selected' : '' ?>><?= $b ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Frequency (MHz)</label>
        <input type="number" id="freq" name="freq" class="form-control" step="0.001" min="0"
               value="<?= h($edit_qso['freq'] ?? '') ?>" placeholder="14.225">
      </div>
      <div class="col-md-3">
        <label class="form-label">Mode *</label>
        <select id="mode" name="mode" class="form-select" required>
          <?php foreach (MODES as $m): ?>
          <option <?= ($edit_qso['mode'] ?? 'SSB') === $m ? 'selected' : '' ?>><?= $m ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Submode</label>
        <input type="text" name="submode" class="form-control" style="text-transform:uppercase"
               value="<?= h($edit_qso['submode'] ?? '') ?>" placeholder="e.g. USB, LSB">
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-2">
        <label class="form-label">RST Sent</label>
        <input type="text" id="rst_sent" name="rst_sent" class="form-control"
               value="<?= h($edit_qso['rst_sent'] ?? '59') ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label">RST Rcvd</label>
        <input type="text" id="rst_rcvd" name="rst_rcvd" class="form-control"
               value="<?= h($edit_qso['rst_rcvd'] ?? '59') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">TX Power (W)</label>
        <input type="number" name="tx_pwr" class="form-control" step="0.1" min="0"
               value="<?= h($edit_qso['tx_pwr'] ?? '') ?>">
      </div>
      <div class="col-md-5">
        <label class="form-label">Operator Name</label>
        <input type="text" id="name" name="name" class="form-control"
               value="<?= h($edit_qso['name'] ?? '') ?>">
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <label class="form-label">QTH</label>
        <input type="text" id="qth" name="qth" class="form-control"
               value="<?= h($edit_qso['qth'] ?? '') ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label">Grid Square</label>
        <input type="text" id="gridsquare" name="gridsquare" class="form-control" style="text-transform:uppercase"
               value="<?= h($edit_qso['gridsquare'] ?? '') ?>" placeholder="FN31">
      </div>
      <div class="col-md-3">
        <label class="form-label">Country</label>
        <input type="text" name="country" class="form-control"
               value="<?= h($edit_qso['country'] ?? '') ?>">
      </div>
      <div class="col-md-1">
        <label class="form-label">Cont</label>
        <input type="text" name="cont" class="form-control" style="text-transform:uppercase" maxlength="2"
               value="<?= h($edit_qso['cont'] ?? '') ?>">
      </div>
      <div class="col-md-1">
        <label class="form-label">CQZ</label>
        <input type="number" name="cqz" class="form-control" min="1" max="40"
               value="<?= h($edit_qso['cqz'] ?? '') ?>">
      </div>
      <div class="col-md-1">
        <label class="form-label">ITUZ</label>
        <input type="number" name="ituz" class="form-control" min="1" max="90"
               value="<?= h($edit_qso['ituz'] ?? '') ?>">
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-2">
        <label class="form-label">DXCC #</label>
        <input type="number" name="dxcc" class="form-control"
               value="<?= h($edit_qso['dxcc'] ?? '') ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label">IOTA</label>
        <input type="text" name="iota" class="form-control" style="text-transform:uppercase"
               value="<?= h($edit_qso['iota'] ?? '') ?>" placeholder="EU-001">
      </div>
      <div class="col-md-8">
        <label class="form-label">Comment</label>
        <input type="text" name="comment" class="form-control"
               value="<?= h($edit_qso['comment'] ?? '') ?>">
      </div>
    </div>

    <div class="mb-3">
      <label class="form-label">Private Notes</label>
      <textarea name="notes" class="form-control" rows="2"><?= h($edit_qso['notes'] ?? '') ?></textarea>
    </div>

    <!-- QSL Status -->
    <div class="card mb-3" style="background:#0a120a">
      <div class="card-header" style="font-size:.8rem">QSL Status</div>
      <div class="card-body">
        <div class="row g-3">
          <?php
          $qsl_fields = [
              'lotw_qsl_sent' => 'LoTW Sent',
              'lotw_qsl_rcvd' => 'LoTW Rcvd',
              'eqsl_qsl_sent' => 'eQSL Sent',
              'eqsl_qsl_rcvd' => 'eQSL Rcvd',
              'qsl_sent'      => 'QSL Card Sent',
              'qsl_rcvd'      => 'QSL Card Rcvd',
          ];
          foreach ($qsl_fields as $field => $label):
          $sentField = str_contains($field, 'sent');
          $opts = $sentField ? ['N'=>'No','Y'=>'Yes','Q'=>'Queued','I'=>'Ignore'] : ['N'=>'No','Y'=>'Yes','R'=>'Requested','I'=>'Ignore'];
          ?>
          <div class="col-md-2 col-6">
            <label class="form-label" style="font-size:.75rem"><?= $label ?></label>
            <select name="<?= $field ?>" class="form-select form-select-sm">
              <?php foreach ($opts as $val => $lbl): ?>
              <option value="<?= $val ?>" <?= ($edit_qso[$field] ?? 'N') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Buttons -->
    <div class="d-flex gap-2">
      <button type="submit" class="btn btn-success">
        <i class="bi bi-check-lg"></i> <?= $edit_qso ? 'Update QSO' : 'Save QSO' ?>
      </button>
      <?php if ($edit_qso): ?>
      <button type="submit" name="delete_qso" value="1" class="btn btn-outline-danger"
              data-confirm="Delete this QSO? This cannot be undone.">
        <i class="bi bi-trash"></i> Delete
      </button>
      <?php endif; ?>
      <a href="<?= BASE_URL ?>/logbook.php" class="btn btn-secondary ms-auto">
        <i class="bi bi-x-lg"></i> Cancel
      </a>
    </div>
  </form>
  </div>
</div>
</div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
