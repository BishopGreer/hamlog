<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

session_start_hamlog();
$user = require_login();
$pdo  = db();

$station_id = (int)($_GET['station'] ?? 0);

// Verify user owns this club station
$station = null;
if ($station_id) {
    $st = $pdo->prepare('SELECT * FROM stations WHERE id = ? AND is_club_station = 1');
    $st->execute([$station_id]);
    $station = $st->fetch();
    if (!$station || ($station['owner_id'] != $user['id'] && !$user['is_admin'])) {
        flash('error', 'Access denied or station not found.'); redirect('/stations.php');
    }
}

// Add member
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_member'])) {
    $username = trim($_POST['username'] ?? '');
    $role     = in_array($_POST['role'] ?? '', ['operator','admin']) ? $_POST['role'] : 'operator';
    $st = $pdo->prepare('SELECT id FROM users WHERE username = ? OR callsign = ?');
    $st->execute([$username, strtoupper($username)]);
    $found = $st->fetch();
    if (!$found) {
        flash('error', "User '$username' not found.");
    } else {
        try {
            $pdo->prepare('INSERT INTO club_members (station_id, user_id, role) VALUES (?,?,?)')
                ->execute([$station_id, $found['id'], $role]);
            flash('success', "Member added.");
        } catch (PDOException) {
            flash('warning', "User is already a member.");
        }
    }
    redirect('/clubs.php?station=' . $station_id);
}

// Remove member
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_member'])) {
    $mid = (int)$_POST['member_id'];
    $pdo->prepare('DELETE FROM club_members WHERE id = ? AND station_id = ?')->execute([$mid, $station_id]);
    flash('success', 'Member removed.');
    redirect('/clubs.php?station=' . $station_id);
}

// List club stations the user owns
$club_stations = [];
$st = $pdo->prepare('SELECT * FROM stations WHERE owner_id = ? AND is_club_station = 1 ORDER BY callsign');
$st->execute([$user['id']]);
$club_stations = $st->fetchAll();

// Members of selected station
$members = [];
if ($station_id) {
    $st = $pdo->prepare(
        'SELECT cm.id as cm_id, cm.role, cm.added_at, u.username, u.callsign, u.name
         FROM club_members cm
         JOIN users u ON u.id = cm.user_id
         WHERE cm.station_id = ?
         ORDER BY cm.role DESC, u.callsign'
    );
    $st->execute([$station_id]);
    $members = $st->fetchAll();
}

$page_title = 'Club Logs';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center mb-3">
  <h4 class="mb-0 text-success"><i class="bi bi-people"></i> Club Log Management</h4>
</div>

<?php if (empty($club_stations)): ?>
<div class="card">
  <div class="card-body text-center py-5">
    <i class="bi bi-people" style="font-size:3rem;color:#2a4a2a"></i>
    <h5 class="mt-3">No club stations</h5>
    <p class="text-muted">Mark a station as a "Club Station" to enable multi-operator access.</p>
    <a href="<?= BASE_URL ?>/stations.php?action=new" class="btn btn-success">
      <i class="bi bi-plus-circle"></i> Add Club Station
    </a>
  </div>
</div>
<?php else: ?>

<div class="row g-4">
  <!-- Club station selector -->
  <div class="col-md-4">
    <div class="card">
      <div class="card-header">Club Stations</div>
      <div class="list-group list-group-flush">
        <?php foreach ($club_stations as $cs): ?>
        <a href="?station=<?= $cs['id'] ?>"
           class="list-group-item list-group-item-action <?= $station_id === $cs['id'] ? 'active' : '' ?>"
           style="background:<?= $station_id === $cs['id'] ? '#0c2c0c' : '#111' ?>;border-color:#1a3a1a;color:#d4d4d4">
          <span class="callsign"><?= h($cs['callsign']) ?></span>
          <?php if ($cs['name']): ?><br><small class="text-muted"><?= h($cs['name']) ?></small><?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Members panel -->
  <div class="col-md-8">
    <?php if (!$station): ?>
    <div class="card">
      <div class="card-body text-center py-4 text-muted">Select a club station to manage members.</div>
    </div>
    <?php else: ?>
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-person-check"></i> Members — <span class="callsign"><?= h($station['callsign']) ?></span></span>
        <span class="text-muted small"><?= count($members) ?> member(s)</span>
      </div>
      <div class="card-body p-0">
        <?php if (empty($members)): ?>
        <p class="text-muted text-center py-3">No members yet. Add operators below.</p>
        <?php else: ?>
        <table class="table table-sm mb-0">
          <thead><tr><th>Callsign</th><th>Username</th><th>Name</th><th>Role</th><th>Added</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($members as $m): ?>
            <tr>
              <td><span class="callsign"><?= h($m['callsign'] ?? '—') ?></span></td>
              <td><?= h($m['username']) ?></td>
              <td><?= h($m['name'] ?? '') ?></td>
              <td>
                <span class="badge <?= $m['role'] === 'admin' ? 'bg-warning text-dark' : 'bg-secondary' ?>">
                  <?= h($m['role']) ?>
                </span>
              </td>
              <td class="text-muted"><?= h(substr($m['added_at'],0,10)) ?></td>
              <td>
                <form method="post" style="display:inline">
                  <input type="hidden" name="member_id" value="<?= $m['cm_id'] ?>">
                  <button type="submit" name="remove_member" value="1" class="btn btn-outline-danger btn-sm py-0"
                          data-confirm="Remove this member?">
                    <i class="bi bi-person-x"></i>
                  </button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- Add member form -->
    <div class="card">
      <div class="card-header"><i class="bi bi-person-plus"></i> Add Operator</div>
      <div class="card-body">
        <form method="post" class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Username or Callsign</label>
            <input type="text" name="username" class="form-control" required placeholder="e.g. W1AW">
          </div>
          <div class="col-md-4">
            <label class="form-label">Role</label>
            <select name="role" class="form-select">
              <option value="operator">Operator (log QSOs)</option>
              <option value="admin">Admin (manage members)</option>
            </select>
          </div>
          <div class="col-md-2 d-flex align-items-end">
            <button type="submit" name="add_member" value="1" class="btn btn-success w-100">
              <i class="bi bi-plus-circle"></i> Add
            </button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
