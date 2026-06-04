<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

session_start_hamlog();
$user = require_login();
$pdo  = db();

$action = $_GET['action'] ?? 'list';
$edit_id = (int)($_GET['id'] ?? 0);

// Handle form save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sid       = (int)($_POST['id'] ?? 0);
    $callsign  = strtoupper(trim($_POST['callsign'] ?? ''));
    $name      = trim($_POST['name'] ?? '');
    $desc      = trim($_POST['description'] ?? '');
    $grid      = strtoupper(trim($_POST['grid_locator'] ?? ''));
    $lat       = $_POST['latitude'] !== '' ? (float)$_POST['latitude'] : null;
    $lon       = $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null;
    $is_club   = isset($_POST['is_club_station']) ? 1 : 0;
    $dxcc      = (int)($_POST['dxcc_entity'] ?? 291);

    if (empty($callsign)) { flash('error', 'Callsign is required.'); redirect('/stations.php'); }

    if ($sid) {
        // Verify ownership
        $st = $pdo->prepare('SELECT owner_id FROM stations WHERE id = ?'); $st->execute([$sid]);
        $row = $st->fetch();
        if (!$row || ($row['owner_id'] != $user['id'] && !$user['is_admin'])) {
            flash('error', 'Access denied.'); redirect('/stations.php');
        }
        $pdo->prepare('UPDATE stations SET callsign=?,name=?,description=?,grid_locator=?,latitude=?,longitude=?,dxcc_entity=?,is_club_station=? WHERE id=?')
            ->execute([$callsign,$name,$desc,$grid,$lat,$lon,$dxcc,$is_club,$sid]);
        flash('success', "Station $callsign updated.");
    } else {
        $st = $pdo->prepare('INSERT INTO stations (owner_id,callsign,name,description,grid_locator,latitude,longitude,dxcc_entity,is_club_station) VALUES (?,?,?,?,?,?,?,?,?)');
        $st->execute([$user['id'],$callsign,$name,$desc,$grid,$lat,$lon,$dxcc,$is_club]);
        $sid = (int)$pdo->lastInsertId();
        // Create default logbook
        $pdo->prepare('INSERT INTO logbooks (station_id,name,is_default) VALUES (?,?,1)')->execute([$sid,'Main Log']);
        flash('success', "Station $callsign created.");
    }
    redirect('/stations.php');
}

// Handle delete
if ($action === 'delete' && $edit_id) {
    $st = $pdo->prepare('SELECT owner_id FROM stations WHERE id = ?'); $st->execute([$edit_id]);
    $row = $st->fetch();
    if ($row && ($row['owner_id'] == $user['id'] || $user['is_admin'])) {
        $pdo->prepare('DELETE FROM stations WHERE id = ?')->execute([$edit_id]);
        flash('success', 'Station deleted.');
    } else {
        flash('error', 'Access denied.');
    }
    redirect('/stations.php');
}

// Fetch station for editing
$edit_station = null;
if ($edit_id && in_array($action, ['edit'])) {
    $st = $pdo->prepare('SELECT * FROM stations WHERE id = ?'); $st->execute([$edit_id]);
    $edit_station = $st->fetch() ?: null;
}

// List all user stations
$my_stations = get_user_stations($user['id']);

// Fetch logbooks count per station
$lb_counts = [];
if ($my_stations) {
    $ids = implode(',', array_column($my_stations, 'id'));
    $st = $pdo->query("SELECT station_id, COUNT(*) as cnt FROM logbooks WHERE station_id IN ($ids) GROUP BY station_id");
    foreach ($st->fetchAll() as $r) $lb_counts[$r['station_id']] = $r['cnt'];
}

$dxcc_entities = $pdo->query('SELECT adif, name, prefix FROM dxcc_entities ORDER BY name')->fetchAll();
$page_title = 'Stations';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="mb-0 text-success"><i class="bi bi-antenna"></i> My Stations</h4>
  <a href="<?= BASE_URL ?>/stations.php?action=new" class="btn btn-success btn-sm">
    <i class="bi bi-plus-circle"></i> Add Station
  </a>
</div>

<?php if ($action === 'new' || $action === 'edit'): ?>
<!-- Add/Edit form -->
<div class="card mb-4">
  <div class="card-header"><?= $edit_station ? 'Edit Station' : 'New Station' ?></div>
  <div class="card-body">
    <form method="post">
      <?php if ($edit_station): ?>
      <input type="hidden" name="id" value="<?= $edit_station['id'] ?>">
      <?php endif; ?>
      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label">Callsign *</label>
          <input type="text" name="callsign" class="form-control" style="text-transform:uppercase;font-family:monospace"
                 value="<?= h($edit_station['callsign'] ?? '') ?>" required>
        </div>
        <div class="col-md-5">
          <label class="form-label">Station Name</label>
          <input type="text" name="name" class="form-control"
                 value="<?= h($edit_station['name'] ?? '') ?>" placeholder="e.g. Home Station">
        </div>
        <div class="col-md-4">
          <label class="form-label">Grid Locator</label>
          <input type="text" name="grid_locator" class="form-control" style="text-transform:uppercase"
                 value="<?= h($edit_station['grid_locator'] ?? '') ?>" placeholder="FN31pr">
        </div>
        <div class="col-md-6">
          <label class="form-label">DXCC Entity</label>
          <select name="dxcc_entity" class="form-select">
            <?php foreach ($dxcc_entities as $d): ?>
            <option value="<?= $d['adif'] ?>" <?= ($edit_station['dxcc_entity'] ?? 291) == $d['adif'] ? 'selected' : '' ?>>
              <?= h($d['prefix'] . ' — ' . $d['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Latitude</label>
          <input type="number" name="latitude" class="form-control" step="0.000001"
                 value="<?= h($edit_station['latitude'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Longitude</label>
          <input type="number" name="longitude" class="form-control" step="0.000001"
                 value="<?= h($edit_station['longitude'] ?? '') ?>">
        </div>
        <div class="col-12">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="2"><?= h($edit_station['description'] ?? '') ?></textarea>
        </div>
        <div class="col-12">
          <div class="form-check">
            <input type="checkbox" class="form-check-input" name="is_club_station" id="is_club"
                   <?= ($edit_station['is_club_station'] ?? 0) ? 'checked' : '' ?>>
            <label class="form-check-label" for="is_club">This is a club station (other users can be added as operators)</label>
          </div>
        </div>
        <div class="col-12 d-flex gap-2">
          <button type="submit" class="btn btn-success"><i class="bi bi-check-lg"></i> Save Station</button>
          <a href="<?= BASE_URL ?>/stations.php" class="btn btn-secondary"><i class="bi bi-x-lg"></i> Cancel</a>
        </div>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Station list -->
<?php if (empty($my_stations)): ?>
<div class="card">
  <div class="card-body text-center py-5">
    <i class="bi bi-antenna" style="font-size:3rem;color:#2a4a2a"></i>
    <h5 class="mt-3">No stations yet</h5>
    <p class="text-muted">Add your first station to start logging.</p>
    <a href="?action=new" class="btn btn-success"><i class="bi bi-plus-circle"></i> Add Station</a>
  </div>
</div>
<?php else: ?>
<div class="row g-3">
  <?php foreach ($my_stations as $s):
  $qso_count = qso_count_for_station($s['id']);
  ?>
  <div class="col-md-6 col-xl-4">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="callsign"><?= h($s['callsign']) ?></span>
        <div class="d-flex gap-1">
          <?php if ($s['is_club_station']): ?>
          <span class="badge bg-secondary">Club</span>
          <?php endif; ?>
          <?php if ($s['role'] === 'owner'): ?>
          <a href="?action=edit&id=<?= $s['id'] ?>" class="btn btn-outline-success btn-sm py-0">
            <i class="bi bi-pencil"></i>
          </a>
          <a href="?action=delete&id=<?= $s['id'] ?>" class="btn btn-outline-danger btn-sm py-0"
             data-confirm="Delete station <?= h($s['callsign']) ?> and ALL its QSOs? This is permanent.">
            <i class="bi bi-trash"></i>
          </a>
          <?php endif; ?>
        </div>
      </div>
      <div class="card-body">
        <?php if ($s['name']): ?><div class="fw-semibold mb-1"><?= h($s['name']) ?></div><?php endif; ?>
        <?php if ($s['description']): ?><p class="text-muted small mb-1"><?= h($s['description']) ?></p><?php endif; ?>
        <div class="d-flex gap-3 text-muted small mt-2">
          <?php if ($s['grid_locator']): ?>
          <span><i class="bi bi-geo-alt"></i> <?= h($s['grid_locator']) ?></span>
          <?php endif; ?>
          <span><i class="bi bi-journal-text"></i> <?= number_format($qso_count) ?> QSOs</span>
          <span><i class="bi bi-book"></i> <?= $lb_counts[$s['id']] ?? 0 ?> logbook(s)</span>
        </div>
      </div>
      <?php if ($s['is_club_station'] && $s['role'] === 'owner'): ?>
      <div class="card-footer">
        <a href="<?= BASE_URL ?>/clubs.php?station=<?= $s['id'] ?>" class="btn btn-outline-success btn-sm">
          <i class="bi bi-people"></i> Manage Members
        </a>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
