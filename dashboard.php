<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

session_start_hamlog();
$user = require_login();
$page_title = 'Dashboard';

$pdo = db();
$stations = get_user_stations($user['id']);
$active_sid = (int)($_SESSION['active_station'] ?? ($stations[0]['id'] ?? 0));

// Fetch active station info
$station = null;
if ($active_sid) {
    $st = $pdo->prepare('SELECT * FROM stations WHERE id = ?');
    $st->execute([$active_sid]);
    $station = $st->fetch();
}

// Stats for active station
$total_qsos   = 0;
$total_dxcc   = 0;
$total_lotw_c = 0;
$recent_qsos  = [];
$band_stats   = [];
$mode_stats   = [];

if ($active_sid) {
    $st = $pdo->prepare('SELECT COUNT(*) FROM qsos WHERE station_id = ?'); $st->execute([$active_sid]);
    $total_qsos = (int)$st->fetchColumn();

    $st = $pdo->prepare('SELECT COUNT(DISTINCT dxcc) FROM qsos WHERE station_id = ? AND dxcc IS NOT NULL'); $st->execute([$active_sid]);
    $total_dxcc = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM qsos WHERE station_id = ? AND lotw_qsl_rcvd IN ('Y','R')"); $st->execute([$active_sid]);
    $total_lotw_c = (int)$st->fetchColumn();

    $st = $pdo->prepare(
        'SELECT q.*, lb.name as logbook_name FROM qsos q
         JOIN logbooks lb ON lb.id = q.logbook_id
         WHERE q.station_id = ? ORDER BY q.date_on DESC, q.time_on DESC LIMIT 15'
    );
    $st->execute([$active_sid]);
    $recent_qsos = $st->fetchAll();

    $st = $pdo->prepare('SELECT band, COUNT(*) as cnt FROM qsos WHERE station_id = ? AND band IS NOT NULL GROUP BY band ORDER BY cnt DESC LIMIT 10');
    $st->execute([$active_sid]);
    $band_stats = $st->fetchAll();

    $st = $pdo->prepare('SELECT mode, COUNT(*) as cnt FROM qsos WHERE station_id = ? GROUP BY mode ORDER BY cnt DESC LIMIT 8');
    $st->execute([$active_sid]);
    $mode_stats = $st->fetchAll();
}

include __DIR__ . '/includes/header.php';
?>

<div class="row g-3 mb-4">
  <div class="col-12">
    <div class="d-flex align-items-center gap-3">
      <h4 class="mb-0 text-success">
        <i class="bi bi-speedometer2"></i> Dashboard
        <?php if ($station): ?>
        — <span class="callsign"><?= h($station['callsign']) ?></span>
        <?php if ($station['is_club_station']): ?>
        <span class="badge bg-secondary ms-1">Club</span>
        <?php endif; ?>
        <?php endif; ?>
      </h4>
      <?php if ($station): ?>
      <a href="<?= BASE_URL ?>/log.php" class="btn btn-success btn-sm ms-auto">
        <i class="bi bi-pencil-square"></i> Log QSO
      </a>
      <?php endif; ?>
    </div>
    <?php if ($station && $station['grid_locator']): ?>
    <small class="text-muted"><i class="bi bi-geo-alt"></i> Grid: <?= h($station['grid_locator']) ?></small>
    <?php endif; ?>
  </div>
</div>

<?php if (!$station): ?>
<div class="card">
  <div class="card-body text-center py-5">
    <i class="bi bi-antenna" style="font-size:3rem;color:#2a4a2a"></i>
    <h5 class="mt-3">No station configured yet</h5>
    <p class="text-muted">Add your first station to start logging contacts.</p>
    <a href="<?= BASE_URL ?>/stations.php?action=new" class="btn btn-success">
      <i class="bi bi-plus-circle"></i> Add Station
    </a>
  </div>
</div>
<?php else: ?>

<!-- Stats row -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-number"><?= number_format($total_qsos) ?></div>
      <div class="stat-label">Total QSOs</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-number"><?= number_format($total_dxcc) ?></div>
      <div class="stat-label">DXCC Entities</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-number"><?= number_format($total_lotw_c) ?></div>
      <div class="stat-label">LoTW Confirmed</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-number"><?= count($band_stats) ?></div>
      <div class="stat-label">Active Bands</div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <!-- Band breakdown -->
  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-bar-chart"></i> QSOs by Band</div>
      <div class="card-body">
        <?php if (empty($band_stats)): ?>
        <p class="text-muted text-center mt-3">No data yet</p>
        <?php else:
        $band_max = max(array_column($band_stats, 'cnt'));
        foreach ($band_stats as $b): ?>
        <div class="d-flex align-items-center gap-2 mb-2">
          <span class="badge-band" style="width:55px;text-align:right"><?= h($b['band']) ?></span>
          <div class="flex-grow-1">
            <div class="progress">
              <div class="progress-bar" style="width:<?= round($b['cnt']/$band_max*100) ?>%"></div>
            </div>
          </div>
          <span class="text-muted" style="font-size:.78rem;min-width:40px;text-align:right"><?= number_format($b['cnt']) ?></span>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <!-- Mode breakdown -->
  <div class="col-md-3">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-soundwave"></i> QSOs by Mode</div>
      <div class="card-body">
        <?php if (empty($mode_stats)): ?>
        <p class="text-muted text-center mt-3">No data yet</p>
        <?php else:
        $mode_max = max(array_column($mode_stats, 'cnt'));
        foreach ($mode_stats as $m): ?>
        <div class="d-flex align-items-center gap-2 mb-2">
          <span class="badge-mode" style="width:65px;text-align:right"><?= h($m['mode']) ?></span>
          <div class="flex-grow-1">
            <div class="progress">
              <div class="progress-bar" style="width:<?= round($m['cnt']/$mode_max*100) ?>;background:#4ab8ff"></div>
            </div>
          </div>
          <span class="text-muted" style="font-size:.78rem;min-width:40px;text-align:right"><?= number_format($m['cnt']) ?></span>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <!-- Quick log -->
  <div class="col-md-5">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-pencil-square"></i> Quick Log</div>
      <div class="card-body">
        <form method="post" action="<?= BASE_URL ?>/log.php">
          <input type="hidden" name="station_id" value="<?= $active_sid ?>">
          <div class="row g-2">
            <div class="col-12">
              <input type="text" name="call" class="form-control form-control-sm" placeholder="Callsign" style="text-transform:uppercase" required>
            </div>
            <div class="col-6">
              <select name="band" class="form-select form-select-sm">
                <?php foreach (BANDS as $b => $_): ?>
                <option value="<?= $b ?>"><?= $b ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <select name="mode" class="form-select form-select-sm">
                <?php foreach (MODES as $m): ?>
                <option <?= $m === 'SSB' ? 'selected' : '' ?>><?= $m ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <input type="text" name="rst_sent" class="form-control form-control-sm" placeholder="RST Sent" value="59">
            </div>
            <div class="col-6">
              <input type="text" name="rst_rcvd" class="form-control form-control-sm" placeholder="RST Rcvd" value="59">
            </div>
            <div class="col-12">
              <button type="submit" name="quick_log" value="1" class="btn btn-success btn-sm w-100">
                <i class="bi bi-plus-circle"></i> Log Contact
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Recent QSOs -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-journal-text"></i> Recent QSOs</span>
    <a href="<?= BASE_URL ?>/logbook.php" class="btn btn-outline-success btn-sm">View All</a>
  </div>
  <div class="card-body p-0">
    <?php if (empty($recent_qsos)): ?>
    <div class="text-center py-4 text-muted">No contacts logged yet. <a href="<?= BASE_URL ?>/log.php" class="text-success">Log your first QSO!</a></div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover table-striped mb-0">
        <thead>
          <tr>
            <th>Date (UTC)</th>
            <th>Time</th>
            <th>Call</th>
            <th>Band</th>
            <th>Mode</th>
            <th>RST S/R</th>
            <th>Name</th>
            <th>QTH</th>
            <th>LoTW</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recent_qsos as $q): ?>
          <tr data-href="<?= BASE_URL ?>/log.php?edit=<?= $q['id'] ?>">
            <td class="text-muted"><?= h($q['date_on']) ?></td>
            <td class="text-muted"><?= substr($q['time_on'], 0, 5) ?>z</td>
            <td><span class="callsign"><?= h($q['call']) ?></span></td>
            <td><span class="badge-band"><?= h($q['band'] ?? '') ?></span></td>
            <td><span class="badge-mode"><?= h($q['mode']) ?></span></td>
            <td class="text-muted"><?= h($q['rst_sent']) ?> / <?= h($q['rst_rcvd']) ?></td>
            <td><?= h($q['name'] ?? '') ?></td>
            <td class="text-muted"><?= h($q['qth'] ?? '') ?></td>
            <td>
              <span class="qsl-<?= strtolower($q['lotw_qsl_rcvd']) ?>"><?= $q['lotw_qsl_rcvd'] ?></span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
