<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

session_start_hamlog();
$user = require_login();
$pdo  = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $callsign = strtoupper(trim($_POST['callsign'] ?? ''));
    $email    = trim($_POST['email'] ?? '');
    $grid     = strtoupper(trim($_POST['grid_locator'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Invalid email address.'); redirect('/settings.php');
    }

    // Check email uniqueness
    $st = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
    $st->execute([$email, $user['id']]);
    if ($st->fetch()) { flash('error', 'Email already in use.'); redirect('/settings.php'); }

    $pdo->prepare('UPDATE users SET name=?, callsign=?, email=?, grid_locator=? WHERE id=?')
        ->execute([$name, $callsign, $email, $grid, $user['id']]);

    // Password change
    $pw = $_POST['new_password'] ?? '';
    if ($pw) {
        if (strlen($pw) < 8) { flash('error', 'Password must be at least 8 characters.'); redirect('/settings.php'); }
        $confirm = $_POST['confirm_password'] ?? '';
        if ($pw !== $confirm) { flash('error', 'Passwords do not match.'); redirect('/settings.php'); }
        $current = $_POST['current_password'] ?? '';
        if (!password_verify($current, $user['password_hash'])) { flash('error', 'Current password incorrect.'); redirect('/settings.php'); }
        $hash = password_hash($pw, PASSWORD_BCRYPT, ['cost'=>12]);
        $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash, $user['id']]);
    }

    flash('success', 'Settings saved.');
    redirect('/settings.php');
}

// Refresh user
$st = $pdo->prepare('SELECT * FROM users WHERE id = ?'); $st->execute([$user['id']]); $user = $st->fetch();

$page_title = 'Settings';
include __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
<div class="col-xl-7">
<h4 class="mb-3 text-success"><i class="bi bi-sliders"></i> Account Settings</h4>

<div class="card mb-4">
  <div class="card-header">Profile</div>
  <div class="card-body">
    <form method="post">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Callsign</label>
          <input type="text" name="callsign" class="form-control" style="text-transform:uppercase;font-family:monospace"
                 value="<?= h($user['callsign'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Name</label>
          <input type="text" name="name" class="form-control" value="<?= h($user['name'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control" value="<?= h($user['email']) ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Grid Locator</label>
          <input type="text" name="grid_locator" class="form-control" style="text-transform:uppercase"
                 value="<?= h($user['grid_locator'] ?? '') ?>" placeholder="FN31pr">
        </div>
        <div class="col-12">
          <hr class="border-secondary">
          <h6 class="text-muted">Change Password <small>(leave blank to keep current)</small></h6>
        </div>
        <div class="col-md-4">
          <label class="form-label">Current Password</label>
          <input type="password" name="current_password" class="form-control">
        </div>
        <div class="col-md-4">
          <label class="form-label">New Password</label>
          <input type="password" name="new_password" class="form-control">
        </div>
        <div class="col-md-4">
          <label class="form-label">Confirm New Password</label>
          <input type="password" name="confirm_password" class="form-control">
        </div>
        <div class="col-12">
          <button type="submit" class="btn btn-success"><i class="bi bi-check-lg"></i> Save Settings</button>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">Account Info</div>
  <div class="card-body">
    <dl class="row mb-0">
      <dt class="col-sm-4 text-muted">Username</dt>
      <dd class="col-sm-8"><?= h($user['username']) ?></dd>
      <dt class="col-sm-4 text-muted">Role</dt>
      <dd class="col-sm-8"><?= $user['is_admin'] ? '<span class="badge bg-warning text-dark">Admin</span>' : '<span class="badge bg-secondary">User</span>' ?></dd>
      <dt class="col-sm-4 text-muted">Member Since</dt>
      <dd class="col-sm-8"><?= h(substr($user['created_at'],0,10)) ?></dd>
    </dl>
  </div>
</div>
</div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
