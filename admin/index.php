<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

session_start_hamlog();
$user = require_admin();
$pdo  = db();

// Save settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $keys = ['site_name','allow_registration','clublog_app_key'];
    foreach ($keys as $k) {
        $val = trim($_POST[$k] ?? '');
        $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?")->execute([$k,$val,$val]);
    }
    flash('success', 'Settings saved.');
    redirect('/admin/index.php');
}

// User management
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_admin'])) {
    $uid = (int)$_POST['user_id'];
    if ($uid !== $user['id']) {
        $st = $pdo->prepare('SELECT is_admin FROM users WHERE id = ?'); $st->execute([$uid]);
        $row = $st->fetch();
        if ($row) {
            $pdo->prepare('UPDATE users SET is_admin=? WHERE id=?')->execute([$row['is_admin'] ? 0 : 1, $uid]);
        }
    }
    redirect('/admin/index.php?tab=users');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $uid = (int)$_POST['user_id'];
    if ($uid !== $user['id']) {
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
        flash('success', 'User deleted.');
    }
    redirect('/admin/index.php?tab=users');
}

// Stats
$user_count  = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$qso_count   = (int)$pdo->query('SELECT COUNT(*) FROM qsos')->fetchColumn();
$station_count = (int)$pdo->query('SELECT COUNT(*) FROM stations')->fetchColumn();

$all_users = $pdo->query(
    'SELECT u.*, COUNT(DISTINCT s.id) as station_count,
     (SELECT COUNT(*) FROM qsos q JOIN stations st ON st.id=q.station_id WHERE st.owner_id=u.id) as qso_count
     FROM users u LEFT JOIN stations s ON s.owner_id=u.id
     GROUP BY u.id ORDER BY u.created_at DESC'
)->fetchAll();

$site_settings = [];
$rows = $pdo->query('SELECT `key`, `value` FROM settings')->fetchAll();
foreach ($rows as $r) $site_settings[$r['key']] = $r['value'];

$tab = $_GET['tab'] ?? 'settings';
$page_title = 'Admin';
include __DIR__ . '/../includes/header.php';
?>

<div class="mb-3">
  <h4 class="mb-2 text-success"><i class="bi bi-gear"></i> Administration</h4>
  <ul class="nav nav-pills">
    <li class="nav-item"><a class="nav-link <?= $tab==='settings'?'active':'' ?>" href="?tab=settings"><i class="bi bi-sliders"></i> Settings</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='users'?'active':'' ?>" href="?tab=users"><i class="bi bi-people"></i> Users</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='stats'?'active':'' ?>" href="?tab=stats"><i class="bi bi-bar-chart"></i> Stats</a></li>
    <li class="nav-item"><a class="nav-link" href="qrz.php"><i class="bi bi-search"></i> QRZ</a></li>
    <li class="nav-item"><a class="nav-link" href="update.php"><i class="bi bi-cloud-download"></i> Updates</a></li>
  </ul>
</div>

<?php if ($tab === 'settings'): ?>
<div class="card">
  <div class="card-header">Site Settings</div>
  <div class="card-body">
    <form method="post">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Site Name</label>
          <input type="text" name="site_name" class="form-control" value="<?= h($site_settings['site_name'] ?? 'HamLog') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Allow Registration</label>
          <select name="allow_registration" class="form-select">
            <option value="1" <?= ($site_settings['allow_registration'] ?? '1') === '1' ? 'selected' : '' ?>>Yes — open registration</option>
            <option value="0" <?= ($site_settings['allow_registration'] ?? '1') === '0' ? 'selected' : '' ?>>No — invite only</option>
          </select>
        </div>
        <div class="col-md-6 d-flex align-items-end">
          <a href="qrz.php" class="btn btn-outline-success w-100">
            <i class="bi bi-search"></i> Configure QRZ / HamQTH &amp; Bulk Update →
          </a>
        </div>
        <div class="col-md-6">
          <label class="form-label">ClubLog Application Key</label>
          <input type="text" name="clublog_app_key" class="form-control" value="<?= h($site_settings['clublog_app_key'] ?? '') ?>">
        </div>
        <div class="col-12">
          <button type="submit" name="save_settings" value="1" class="btn btn-success">
            <i class="bi bi-check-lg"></i> Save Settings
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php elseif ($tab === 'users'): ?>
<div class="card">
  <div class="card-header d-flex justify-content-between">
    <span><i class="bi bi-people"></i> Users (<?= $user_count ?>)</span>
  </div>
  <div class="card-body p-0">
    <table class="table table-hover table-striped mb-0">
      <thead><tr><th>Callsign</th><th>Username</th><th>Email</th><th>Stations</th><th>QSOs</th><th>Role</th><th>Joined</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($all_users as $u): ?>
        <tr>
          <td><span class="callsign"><?= h($u['callsign'] ?? '—') ?></span></td>
          <td><?= h($u['username']) ?></td>
          <td class="text-muted"><?= h($u['email']) ?></td>
          <td><?= $u['station_count'] ?></td>
          <td><?= number_format($u['qso_count']) ?></td>
          <td>
            <?php if ($u['is_admin']): ?>
            <span class="badge bg-warning text-dark">Admin</span>
            <?php else: ?>
            <span class="badge bg-secondary">User</span>
            <?php endif; ?>
          </td>
          <td class="text-muted"><?= h(substr($u['created_at'],0,10)) ?></td>
          <td>
            <div class="d-flex gap-1">
              <?php if ($u['id'] !== $user['id']): ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <button type="submit" name="toggle_admin" value="1" class="btn btn-outline-warning btn-sm py-0"
                        title="Toggle admin">
                  <i class="bi bi-shield<?= $u['is_admin'] ? '-x' : '' ?>"></i>
                </button>
              </form>
              <form method="post" style="display:inline">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <button type="submit" name="delete_user" value="1" class="btn btn-outline-danger btn-sm py-0"
                        data-confirm="Delete user <?= h($u['username']) ?> and all their data?">
                  <i class="bi bi-trash"></i>
                </button>
              </form>
              <?php else: ?>
              <span class="text-muted small">(you)</span>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($tab === 'stats'): ?>
<div class="row g-3">
  <div class="col-md-4">
    <div class="stat-card">
      <div class="stat-number"><?= number_format($user_count) ?></div>
      <div class="stat-label">Total Users</div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="stat-card">
      <div class="stat-number"><?= number_format($station_count) ?></div>
      <div class="stat-label">Total Stations</div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="stat-card">
      <div class="stat-number"><?= number_format($qso_count) ?></div>
      <div class="stat-label">Total QSOs</div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
