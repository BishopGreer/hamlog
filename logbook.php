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

// Filters
$search  = trim($_GET['q'] ?? '');
$fband   = trim($_GET['band'] ?? '');
$fmode   = trim($_GET['mode'] ?? '');
$flb     = (int)($_GET['logbook'] ?? 0);
$page    = max(1, (int)($_GET['page'] ?? 1));
$per     = 50;

// Build query
$where  = ['q.station_id = :sid'];
$params = ['sid' => $active_sid];

if ($search) {
    $where[]        = '(q.call LIKE :q OR q.name LIKE :q OR q.qth LIKE :q OR q.country LIKE :q)';
    $params['q']    = "%$search%";
}
if ($fband) { $where[] = 'q.band = :band'; $params['band'] = $fband; }
if ($fmode) { $where[] = 'q.mode = :mode'; $params['mode'] = $fmode; }
if ($flb)   { $where[] = 'q.logbook_id = :lb'; $params['lb'] = $flb; }

$where_sql = implode(' AND ', $where);
$count_st  = $pdo->prepare("SELECT COUNT(*) FROM qsos q WHERE $where_sql");
$count_st->execute($params);
$total = (int)$count_st->fetchColumn();
$pages = (int)ceil($total / $per);
$offset = ($page - 1) * $per;

$st = $pdo->prepare(
    "SELECT q.*, lb.name as logbook_name FROM qsos q
     JOIN logbooks lb ON lb.id = q.logbook_id
     WHERE $where_sql
     ORDER BY q.date_on DESC, q.time_on DESC
     LIMIT :limit OFFSET :offset"
);
$params['limit']  = $per;
$params['offset'] = $offset;
$st->execute($params);
$qsos = $st->fetchAll();

// Available bands/modes for filter dropdowns
$bst = $pdo->prepare('SELECT DISTINCT band FROM qsos WHERE station_id = ? AND band IS NOT NULL ORDER BY band');
$bst->execute([$active_sid]);
$avail_bands = $bst->fetchAll(PDO::FETCH_COLUMN);

$mst = $pdo->prepare('SELECT DISTINCT mode FROM qsos WHERE station_id = ? ORDER BY mode');
$mst->execute([$active_sid]);
$avail_modes = $mst->fetchAll(PDO::FETCH_COLUMN);

$logbooks = $active_sid ? get_station_logbooks($active_sid) : [];

$page_title = 'Logbook';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="mb-0 text-success"><i class="bi bi-journal-text"></i> Logbook</h4>
  <div class="d-flex gap-2">
    <a href="<?= BASE_URL ?>/adif.php?export=1&station=<?= $active_sid ?>" class="btn btn-outline-success btn-sm">
      <i class="bi bi-download"></i> Export ADIF
    </a>
    <a href="<?= BASE_URL ?>/log.php" class="btn btn-success btn-sm">
      <i class="bi bi-plus-circle"></i> Log QSO
    </a>
  </div>
</div>

<!-- Filters -->
<form method="get" class="card mb-3">
  <div class="card-body py-2">
    <div class="row g-2 align-items-end">
      <div class="col-md-4">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search callsign, name, QTH…"
               value="<?= h($search) ?>">
      </div>
      <div class="col-md-2">
        <select name="band" class="form-select form-select-sm">
          <option value="">All Bands</option>
          <?php foreach ($avail_bands as $b): ?>
          <option value="<?= h($b) ?>" <?= $fband === $b ? 'selected' : '' ?>><?= h($b) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="mode" class="form-select form-select-sm">
          <option value="">All Modes</option>
          <?php foreach ($avail_modes as $m): ?>
          <option value="<?= h($m) ?>" <?= $fmode === $m ? 'selected' : '' ?>><?= h($m) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="logbook" class="form-select form-select-sm">
          <option value="">All Logbooks</option>
          <?php foreach ($logbooks as $lb): ?>
          <option value="<?= $lb['id'] ?>" <?= $flb === $lb['id'] ? 'selected' : '' ?>><?= h($lb['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 d-flex gap-1">
        <button type="submit" class="btn btn-outline-success btn-sm flex-grow-1">
          <i class="bi bi-search"></i> Filter
        </button>
        <a href="<?= BASE_URL ?>/logbook.php" class="btn btn-secondary btn-sm">
          <i class="bi bi-x-lg"></i>
        </a>
      </div>
    </div>
  </div>
</form>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-list-ul"></i> <?= number_format($total) ?> QSO<?= $total !== 1 ? 's' : '' ?></span>
    <span class="text-muted small">Page <?= $page ?> of <?= max(1,$pages) ?></span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover table-striped mb-0">
        <thead>
          <tr>
            <th>#</th>
            <th>Date</th>
            <th>Time (UTC)</th>
            <th>Call</th>
            <th>Band</th>
            <th>Freq</th>
            <th>Mode</th>
            <th>RST S</th>
            <th>RST R</th>
            <th>Name</th>
            <th>QTH</th>
            <th>Grid</th>
            <th>LoTW</th>
            <th>eQSL</th>
            <th>QSL</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($qsos)): ?>
          <tr><td colspan="15" class="text-center py-4 text-muted">No contacts found.</td></tr>
          <?php else: foreach ($qsos as $q): ?>
          <tr data-href="<?= BASE_URL ?>/log.php?edit=<?= $q['id'] ?>">
            <td class="text-muted" style="font-size:.75rem"><?= $q['id'] ?></td>
            <td class="text-muted"><?= h($q['date_on']) ?></td>
            <td class="text-muted"><?= substr($q['time_on'],0,5) ?>z</td>
            <td><span class="callsign"><?= h($q['call']) ?></span></td>
            <td><span class="badge-band"><?= h($q['band'] ?? '') ?></span></td>
            <td class="text-muted" style="font-family:monospace;font-size:.8rem"><?= $q['freq'] ? number_format((float)$q['freq'],3) : '' ?></td>
            <td><span class="badge-mode"><?= h($q['mode']) ?></span></td>
            <td class="text-muted"><?= h($q['rst_sent']) ?></td>
            <td class="text-muted"><?= h($q['rst_rcvd']) ?></td>
            <td><?= h($q['name'] ?? '') ?></td>
            <td class="text-muted"><?= h($q['qth'] ?? '') ?></td>
            <td class="text-muted" style="font-family:monospace;font-size:.8rem"><?= h($q['gridsquare'] ?? '') ?></td>
            <td><span class="qsl-<?= strtolower($q['lotw_qsl_rcvd']) ?>"><?= h($q['lotw_qsl_rcvd']) ?></span></td>
            <td><span class="qsl-<?= strtolower($q['eqsl_qsl_rcvd']) ?>"><?= h($q['eqsl_qsl_rcvd']) ?></span></td>
            <td><span class="qsl-<?= strtolower($q['qsl_rcvd']) ?>"><?= h($q['qsl_rcvd']) ?></span></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php if ($pages > 1): ?>
  <div class="card-footer">
    <nav>
      <ul class="pagination pagination-sm mb-0 justify-content-center flex-wrap gap-1">
        <?php
        $qs = http_build_query(['q'=>$search,'band'=>$fband,'mode'=>$fmode,'logbook'=>$flb]);
        for ($p = 1; $p <= $pages; $p++):
        ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
          <a class="page-link" href="?<?= $qs ?>&page=<?= $p ?>"><?= $p ?></a>
        </li>
        <?php endfor; ?>
      </ul>
    </nav>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
